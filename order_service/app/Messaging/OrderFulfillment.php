<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Domain\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Services\Order\OrderPayloadFactory;
use App\Services\Order\RequestStockCommit;
use App\Services\Order\RequestStockRelease;
use App\Support\KafkaPayload;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;

#[Saga]
final class OrderFulfillment
{
    private function __construct(
        #[Identifier]
        public string $sagaId,
        public string $state = 'placed',
        public bool $releaseRequested = false,
    ) {}

    #[EventHandler]
    public static function start(OrderPlaced $event): self
    {
        return new self($event->sagaId);
    }

    public static function restore(string $sagaId, string $state, bool $releaseRequested = false): self
    {
        return new self($sagaId, $state, $releaseRequested);
    }

    #[EventHandler]
    public function onStockReserved(StockReserved $event, RequestStockRelease $release): void
    {
        $body = $event->envelope->payload;
        $orderId = $body['order_id'] ?? null;
        if (! is_numeric($orderId)) {
            return;
        }

        $messageSagaId = KafkaPayload::sagaId($body) ?? $event->sagaId;
        if ($messageSagaId === '') {
            return;
        }

        $order = Order::query()->lockForUpdate()->find((int) $orderId);
        if (! $order instanceof Order || $order->saga_id !== $messageSagaId) {
            return;
        }

        $status = $order->statusEnum();
        if (in_array($status, [OrderStatus::Cancelled, OrderStatus::PaymentFailed], true)) {
            $this->requestRelease($order, $release);
            if ($this->state === 'placed') {
                $this->state = $status === OrderStatus::PaymentFailed ? 'payment_failed' : 'cancelled';
            }

            return;
        }

        if ($status === OrderStatus::Pending && $this->state === 'placed') {
            $order->markAwaitingPayment();
            $order->save();
            $this->state = 'stock_reserved';
        }
    }

    #[EventHandler]
    public function onStockReservationFailed(StockReservationFailed $event, OrderPayloadFactory $payloads): void
    {
        if ($this->state === 'reservation_failed') {
            return;
        }

        $body = $event->envelope->payload;
        $orderId = $body['order_id'] ?? null;
        if (! is_numeric($orderId)) {
            return;
        }

        $messageSagaId = KafkaPayload::sagaId($body) ?? $event->sagaId;
        $order = Order::query()->lockForUpdate()->find((int) $orderId);
        if (! $order instanceof Order || $order->saga_id !== $messageSagaId) {
            return;
        }

        if ($order->statusEnum() !== OrderStatus::Pending) {
            return;
        }

        $order->cancel();
        $order->cancel_reason = 'insufficient_stock';
        $order->save();

        event(new OrderCancelled(
            $payloads->notificationPayload($order, ['reason' => 'insufficient_stock'])
        ));

        $this->state = 'reservation_failed';
    }

    #[EventHandler]
    public function onPaymentCaptured(PaymentCaptured $event, RequestStockCommit $commit): void
    {
        if (in_array($this->state, ['paid', 'cancelled', 'reservation_failed', 'payment_failed'], true)) {
            return;
        }

        $order = $this->order();
        if (! $order instanceof Order) {
            return;
        }

        $commit->dispatch($order);
        $this->state = 'paid';
    }

    #[EventHandler]
    public function onOrderCancelRequested(OrderCancelRequested $event, RequestStockRelease $release): void
    {
        $order = $this->order();
        if ($order instanceof Order) {
            $this->requestRelease($order, $release);
        }

        $this->state = 'cancelled';
    }

    #[EventHandler]
    public function onPaymentFailed(PaymentFailed $event, RequestStockRelease $release): void
    {
        $order = $this->order();
        if ($order instanceof Order) {
            $this->requestRelease($order, $release);
        }

        $this->state = 'payment_failed';
    }

    private function order(): ?Order
    {
        $order = Order::query()->where('saga_id', $this->sagaId)->first();

        return $order instanceof Order ? $order : null;
    }

    private function requestRelease(Order $order, RequestStockRelease $release): void
    {
        if ($this->releaseRequested) {
            return;
        }

        $release->dispatch($order);
        $this->releaseRequested = true;
    }
}
