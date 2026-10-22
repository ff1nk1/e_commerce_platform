<?php

namespace App\Kafka\Handlers;

use App\Contracts\SagaStepStore;
use App\Domain\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\Order;
use App\Services\Order\OrderPayloadFactory;
use App\Services\Order\RequestStockRelease;
use App\Services\Saga\SagaStepRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class StockEventsHandler
{
    public function __construct(
        private SagaStepStore $saga,
        private SagaStepRunner $sagaRunner,
        private OrderPayloadFactory $payloads,
        private RequestStockRelease $stockRelease,
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

        $releaseOrder = null;

        $this->sagaRunner->run($sagaId, 'stock.reserved', function () use ($orderId, $sagaId, &$releaseOrder) {
            return DB::transaction(function () use ($orderId, $sagaId, &$releaseOrder) {
                $order = Order::query()->lockForUpdate()->find((int) $orderId);
                if ($order === null) {
                    return null;
                }

                if ($order->saga_id !== $sagaId) {
                    Log::warning('Kafka stock.reserved saga_id mismatch; ignoring', [
                        'order_id' => $orderId,
                        'expected' => $order->saga_id,
                        'got' => $sagaId,
                    ]);

                    return null;
                }

                $status = $order->statusEnum();

                if ($status === OrderStatus::Pending) {
                    $order->markAwaitingPayment();
                    $order->save();

                    return [
                        'status' => SagaStepStore::STATUS_DONE,
                        'result' => ['order_id' => (int) $orderId, 'outcome' => 'awaiting_payment'],
                    ];
                }

                // Late reserve after user cancelled pending (or payment already failed):
                // stock was reserved after cancel — request release so reserved is not orphaned.
                if (in_array($status, [OrderStatus::Cancelled, OrderStatus::PaymentFailed], true)) {
                    if (! $this->saga->isCompleted($sagaId, 'stock.release_requested')) {
                        $this->stockRelease->dispatch($order);
                        $releaseOrder = $order;
                    }

                    return [
                        'status' => SagaStepStore::STATUS_DONE,
                        'result' => [
                            'order_id' => (int) $orderId,
                            'outcome' => 'compensated_release',
                        ],
                    ];
                }

                return [
                    'status' => SagaStepStore::STATUS_DONE,
                    'result' => [
                        'order_id' => (int) $orderId,
                        'outcome' => 'ignored_status',
                        'status' => $status->value,
                    ],
                ];
            });
        });

        if ($releaseOrder !== null) {
            $this->stockRelease->recordSagaStep($releaseOrder);
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

        $this->sagaRunner->run($sagaId, 'stock.reservation_failed', function () use ($orderId, $sagaId) {
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

            if (! $applied) {
                return null;
            }

            return [
                'status' => SagaStepStore::STATUS_FAILED,
                'result' => ['order_id' => (int) $orderId],
            ];
        });
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
