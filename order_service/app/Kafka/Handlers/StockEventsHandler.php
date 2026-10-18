<?php

namespace App\Kafka\Handlers;

use App\Contracts\SagaStepStore;
use App\Domain\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Services\Order\OrderPayloadFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockEventsHandler
{
    public function __construct(
        private SagaStepStore $saga,
        private OrderPayloadFactory $payloads,
    ) {}

    public function __invoke(ConsumerMessage $message): void
    {
        $headers = $message->getHeaders();
        $eventType = $headers['event-type'] ?? null;
        $payload = $message->getBody();

        if (! is_array($payload)) {
            Log::warning('Kafka: stock event with non-array body');

            return;
        }

        match ($eventType) {
            'stock.reserved' => $this->onReserved($payload),
            'stock.reservation_failed' => $this->onReservationFailed($payload),
            default => Log::debug("Игнорируем неизвестное событие stock.events: {$eventType}"),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onReserved(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        if (! is_numeric($orderId)) {
            return;
        }

        $sagaId = $this->sagaIdFrom($payload);
        if ($sagaId === null) {
            Log::warning('Kafka stock.reserved missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $decision = $this->saga->tryBegin($sagaId, 'stock.reserved');
        if ($decision !== 'run') {
            Log::info('Saga step stock.reserved skipped', [
                'saga_id' => $sagaId,
                'decision' => $decision,
            ]);

            return;
        }

        try {
            $applied = DB::transaction(function () use ($orderId, $sagaId) {
                $order = Order::query()->lockForUpdate()->find((int) $orderId);
                if ($order === null) {
                    return false;
                }

                if ($order->saga_id !== $sagaId) {
                    Log::warning('Kafka stock.reserved saga_id mismatch; ignoring', [
                        'order_id' => $orderId,
                        'expected' => $order->saga_id,
                        'got' => $sagaId,
                    ]);

                    return false;
                }

                if ($order->statusEnum() !== OrderStatus::Pending) {
                    return false;
                }

                $order->markAwaitingPayment();
                $order->save();

                return true;
            });

            if ($applied) {
                $this->saga->put(
                    $sagaId,
                    'stock.reserved',
                    SagaStepStore::STATUS_DONE,
                    ['order_id' => (int) $orderId]
                );
            }
        } finally {
            $this->saga->release($sagaId, 'stock.reserved');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onReservationFailed(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        if (! is_numeric($orderId)) {
            return;
        }

        $sagaId = $this->sagaIdFrom($payload);
        if ($sagaId === null) {
            Log::warning('Kafka stock.reservation_failed missing saga_id; ignoring', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $decision = $this->saga->tryBegin($sagaId, 'stock.reservation_failed');
        if ($decision !== 'run') {
            Log::info('Saga step stock.reservation_failed skipped', [
                'saga_id' => $sagaId,
                'decision' => $decision,
            ]);

            return;
        }

        try {
            $applied = DB::transaction(function () use ($orderId, $sagaId) {
                $order = Order::query()->lockForUpdate()->find((int) $orderId);
                if ($order === null) {
                    return false;
                }

                if ($order->saga_id !== $sagaId) {
                    Log::warning('Kafka stock.reservation_failed saga_id mismatch; ignoring', [
                        'order_id' => $orderId,
                        'expected' => $order->saga_id,
                        'got' => $sagaId,
                    ]);

                    return false;
                }

                if ($order->statusEnum() !== OrderStatus::Pending) {
                    return false;
                }

                $order->cancel();
                $order->save();

                event(new OrderCancelled(
                    $this->payloads->notificationPayload($order, ['reason' => 'insufficient_stock'])
                ));

                return true;
            });

            if ($applied) {
                $this->saga->put(
                    $sagaId,
                    'stock.reservation_failed',
                    SagaStepStore::STATUS_FAILED,
                    ['order_id' => (int) $orderId]
                );
            }
        } finally {
            $this->saga->release($sagaId, 'stock.reservation_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sagaIdFrom(array $payload): ?string
    {
        $sagaId = $payload['saga_id'] ?? null;

        return is_string($sagaId) && $sagaId !== '' ? $sagaId : null;
    }
}
