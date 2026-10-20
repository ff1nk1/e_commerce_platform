<?php

namespace App\Models;

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
class ProductSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'name',
        'price',
        'store_id',
        'store_name',
        'category_id',
        'category_name',
        'category_slug',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }
}
