<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\DTO\Orders\StockLine;
use App\Events\StockCommitRequested;
use App\Models\Order;

final class RequestStockCommitService
{
    /**
     * Dispatch stock.commit_requested (call inside the domain DB transaction).
     */
    public function handle(Order $order): void
    {
        $commitPayload = [
            'order_id' => $order->id,
            'items' => array_map(
                static fn (StockLine $line): array => $line->toArray(),
                StockLine::listFromOrder($order),
            ),
        ];

        if (is_string($order->saga_id) && $order->saga_id !== '') {
            $commitPayload['saga_id'] = $order->saga_id;
        }

        event(new StockCommitRequested($commitPayload));
    }
}
