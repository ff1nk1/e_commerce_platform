<?php

declare(strict_types=1);

namespace App\Saga;

use App\Enums\FulfillmentState;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Stock\CancelUnreservedOrderService;
use App\Services\Stock\MarkAwaitingPaymentService;
use App\Services\Stock\RequestStockCommitService;
use App\Services\Stock\RequestStockReleaseService;
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
    public function onStockReserved(
        StockReserved $event,
        RequestStockReleaseService $release,
        MarkAwaitingPaymentService $markAwaitingPayment,
    ): void {
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
            $markAwaitingPayment->handle($order);
            $this->state = FulfillmentState::StockReserved;
        }
    }

    #[EventHandler]
    public function onStockReservationFailed(StockReservationFailed $event, CancelUnreservedOrderService $cancelUnreserved): void
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

        $cancelUnreserved->handle($order);

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
