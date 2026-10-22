<?php

namespace App\Services;

use App\Events\StockChanged;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function createStock(array $data): Stock
    {
        return DB::transaction(function () use ($data) {
            $stock = Stock::create([
                'product_id' => $data['product_id'],
                'store_id' => $data['store_id'],
                'quantity' => (int) $data['quantity'],
                'reserved' => 0,
            ]);

            $this->queueStockChanged($stock);

            return $stock->load(['product', 'store']);
        });
    }

    public function updateQuantity(Stock $stock, int $quantity): Stock
    {
        return DB::transaction(function () use ($stock, $quantity) {
            $stock->quantity = $quantity;
            $stock->save();

            $this->queueStockChanged($stock);

            return $stock->load(['product', 'store']);
        });
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
