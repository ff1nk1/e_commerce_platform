<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Appends([
    'available',
])]
#[Fillable([
    'product_id',
    'store_id',
    'quantity',
    'reserved',
])]
final class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    /**
     * Получить товар, к которому относится этот остаток.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Получить магазин (склад), на котором числится остаток.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Виртуальный атрибут для получения доступного остатка (свободного для резерва).
     * Доступен через $stock->available
     */
    protected function available(): Attribute
    {
        return Attribute::make(get: function (): int|float {
            return $this->quantity - $this->reserved;
        });
    }

    /**
     * Приведение типов атрибутов.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved' => 'integer',
        ];
    }
}
