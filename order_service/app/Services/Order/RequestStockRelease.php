<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\SagaStepStore;
use App\Events\StockReleaseRequested;
use App\Models\Order;

final class RequestStockRelease
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private SagaStepStore $saga,
    ) {}

    /**
     * Dispatch stock.release_requested (call inside the domain DB transaction).
     */
    public function dispatch(Order $order): void
    {
        $releasePayload = [
            'order_id' => $order->id,
            'items' => $this->payloads->itemsPayload($order),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $releasePayload['saga_id'] = $order->saga_id;
        }

        event(new StockReleaseRequested($releasePayload));
    }

    /**
     * Persist saga step after the DB transaction commits.
     */
    public function recordSagaStep(Order $order): void
    {
        if (! is_string($order->saga_id) || $order->saga_id === '') {
            return;
        }

        $this->saga->put(
            $order->saga_id,
            'stock.release_requested',
            SagaStepStore::STATUS_DONE,
            ['order_id' => $order->id]
        );
    }
}
