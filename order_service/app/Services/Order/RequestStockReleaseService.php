<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\DTO\Orders\StockLine;
use App\Events\StockReleaseRequested;
use App\Models\Order;

final class RequestStockReleaseService
{
    /**
     * Dispatch stock.release_requested (call inside the domain DB transaction).
     */
    public function handle(Order $order): void
    {
        $releasePayload = [
            'order_id' => $order->id,
            'items' => array_map(
                static fn (StockLine $line): array => $line->toArray(),
                StockLine::listFromOrder($order),
            ),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $releasePayload['saga_id'] = $order->saga_id;
        }

        event(new StockReleaseRequested($releasePayload));
    }
}
