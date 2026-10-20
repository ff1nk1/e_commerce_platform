<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory;

    /**
     * Атрибуты, доступные для массового заполнения.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'store_id',
        'quantity',
        'reserved',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'available',
    ];

    /**
     * Получить товар, к которому относится этот остаток.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Получить магазин (склад), на котором числится остаток.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Виртуальный атрибут для получения доступного остатка (свободного для резерва).
     * Доступен через $stock->available
     */
    public function getAvailableAttribute(): int
    {
        return $this->quantity - $this->reserved;
    }
}
