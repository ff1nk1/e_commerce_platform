<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $price
 * @property int|null $store_id
 * @property string|null $store_name
 * @property int|null $category_id
 * @property string|null $category_name
 * @property string|null $category_slug
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

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
