<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockReservationStatus;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $order_id
 * @property int $product_id
 * @property int $store_id
 * @property int $quantity
 * @property StockReservationStatus $status
 */
#[Fillable([
    'order_id',
    'product_id',
    'store_id',
    'quantity',
    'status',
])]
final class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'product_id' => 'integer',
            'store_id' => 'integer',
            'quantity' => 'integer',
            'status' => StockReservationStatus::class,
        ];
    }
}
