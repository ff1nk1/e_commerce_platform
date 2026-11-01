<?php

declare(strict_types=1);

namespace App\Messaging;

use App\DTO\Orders\OrderNotification;
use App\Enums\CancelReason;
use App\Enums\FulfillmentState;
use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Services\Order\RequestStockCommitService;
use App\Services\Order\RequestStockReleaseService;
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
        public FulfillmentState $state = FulfillmentState::Placed,
        public bool $releaseRequested = false,
    ) {}

    #[EventHandler]
    public static function start(OrderPlaced $event): self
    {
        return new self($event->sagaId);
    }

    public static function restore(string $sagaId, FulfillmentState $state, bool $releaseRequested = false): self
    {
        return new self($sagaId, $state, $releaseRequested);
    }

    #[EventHandler]
    public function onStockReserved(StockReserved $event, RequestStockReleaseService $release): void
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
            if ($this->state === FulfillmentState::Placed) {
                $this->state = $status === OrderStatus::PaymentFailed
                    ? FulfillmentState::PaymentFailed
                    : FulfillmentState::Cancelled;
            }

            return;
        }

        if ($status === OrderStatus::Pending && $this->state === FulfillmentState::Placed) {
            $order->markAwaitingPayment();
            $order->save();
            $this->state = FulfillmentState::StockReserved;
        }
    }

    #[EventHandler]
    public function onStockReservationFailed(StockReservationFailed $event): void
    {
        if ($this->state === FulfillmentState::ReservationFailed) {
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
        $order->cancel_reason = CancelReason::InsufficientStock->value;
        $order->save();

        event(new OrderCancelled(
            OrderNotification::fromOrder($order, ['reason' => CancelReason::InsufficientStock->value])->toArray()
        ));

        $this->state = FulfillmentState::ReservationFailed;
    }

    #[EventHandler]
    public function onPaymentCaptured(PaymentCaptured $event, RequestStockCommitService $commit): void
    {
        if (in_array($this->state, [
            FulfillmentState::Paid,
            FulfillmentState::Cancelled,
            FulfillmentState::ReservationFailed,
            FulfillmentState::PaymentFailed,
        ], true)) {
            return;
        }

        $order = $this->order();
        if (! $order instanceof Order) {
            return;
        }

        $commit->handle($order);
        $this->state = FulfillmentState::Paid;
    }

    #[EventHandler]
    public function onOrderCancelRequested(OrderCancelRequested $event, RequestStockReleaseService $release): void
    {
        $order = $this->order();
        if ($order instanceof Order) {
            $this->requestRelease($order, $release);
        }

        $this->state = FulfillmentState::Cancelled;
    }

    #[EventHandler]
    public function onPaymentFailed(PaymentFailed $event, RequestStockReleaseService $release): void
    {
        $order = $this->order();
        if ($order instanceof Order) {
            $this->requestRelease($order, $release);
        }

        $this->state = FulfillmentState::PaymentFailed;
    }

    private function order(): ?Order
    {
        $order = Order::query()->where('saga_id', $this->sagaId)->first();

        return $order instanceof Order ? $order : null;
    }

    private function requestRelease(Order $order, RequestStockReleaseService $release): void
    {
        if ($this->releaseRequested) {
            return;
        }

        $release->handle($order);
        $this->releaseRequested = true;
    }
}
