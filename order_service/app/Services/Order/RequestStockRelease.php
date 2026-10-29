<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Events\StockReleaseRequested;
use App\Models\Order;

final class RequestStockRelease
{
    public function __construct(
        private OrderPayloadFactory $payloads,
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
}
