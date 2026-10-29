<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Events\StockCommitRequested;
use App\Models\Order;

final class RequestStockCommit
{
    public function __construct(
        private OrderPayloadFactory $payloads,
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
}
