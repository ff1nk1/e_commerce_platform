<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Events\StockChanged;
use App\Models\Stock;
use App\Services\Products\ProductCache;
use Illuminate\Support\Facades\DB;

final class UpdateStockQuantityService
{
    public function __construct(
        private ProductCache $cache,
    ) {}

    public function handle(Stock $stock, int $quantity): Stock
    {
        $stock = DB::transaction(function () use ($stock, $quantity): Stock {
            $stock->quantity = $quantity;
            $stock->save();

            $this->queueStockChanged($stock);

            return $stock->load(['product', 'store']);
        });

        $this->cache->clear();

        return $stock;
    }

    private function queueStockChanged(Stock $stock): void
    {
        event(new StockChanged([
            'product_id' => $stock->product_id,
            'store_id' => $stock->store_id,
            // Downstream treats this as sellable/available qty.
            'quantity' => $stock->available,
        ]));
    }
}
