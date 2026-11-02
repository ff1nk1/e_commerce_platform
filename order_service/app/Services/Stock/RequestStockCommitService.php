<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\DTO\Orders\StockLine;
use App\DTO\Orders\StockRequest;
use App\Events\StockCommitRequested;
use App\Models\Order;

final class RequestStockCommitService
{
    /**
     * Dispatch stock.commit_requested (call inside the domain DB transaction).
     */
    public function handle(Order $order): void
    {
        $sagaId = is_string($order->saga_id) && $order->saga_id !== '' ? $order->saga_id : null;

        event(new StockCommitRequested(new StockRequest(
            orderId: (int) $order->id,
            sagaId: $sagaId,
            lines: StockLine::listFromOrder($order),
        )));
    }
}
