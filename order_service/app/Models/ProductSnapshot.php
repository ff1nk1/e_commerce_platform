<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $price
 * @property int|null $store_id
 * @property string|null $store_name
 * @property int|null $category_id
 * @property string|null $category_name
 * @property string|null $category_slug
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'id',
    'name',
    'price',
    'store_id',
    'store_name',
    'category_id',
    'category_name',
    'category_slug',
    'deleted_at',
])]
#[WithoutIncrementing]
final class ProductSnapshot extends Model
{
    use HasFactory;

    protected $keyType = 'int';

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'deleted_at' => 'datetime',
        ];
    }
}
