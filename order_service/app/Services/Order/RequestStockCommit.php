<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\SagaStepStore;
use App\Events\StockCommitRequested;
use App\Models\Order;

final class RequestStockCommit
{
    public function __construct(
        private OrderPayloadFactory $payloads,
        private SagaStepStore $saga,
    ) {}

    /**
     * Dispatch stock.commit_requested (call inside the domain DB transaction).
     */
    public function dispatch(Order $order): void
    {
        $commitPayload = [
            'order_id' => $order->id,
            'items' => $this->payloads->itemsPayload($order),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $commitPayload['saga_id'] = $order->saga_id;
        }

        event(new StockCommitRequested($commitPayload));
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
            'stock.commit_requested',
            SagaStepStore::STATUS_DONE,
            ['order_id' => $order->id]
        );
    }
}
