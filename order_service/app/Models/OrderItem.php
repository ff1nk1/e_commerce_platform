<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $quantity
 * @property string $unit_price
 * @property-read ProductSnapshot|null $productSnapshot
 * @property-read Order|null $order
 */
#[Fillable([
    'order_id',
    'product_id',
    'quantity',
    'unit_price',
])]
final class OrderItem extends Model
{
    use HasFactory;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductSnapshot, $this> */
    public function productSnapshot(): BelongsTo
    {
        return $this->belongsTo(ProductSnapshot::class, 'product_id');
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }
}
