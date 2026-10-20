<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string $value
 */
class ProductAttribute extends Model
{
    use HasFactory;

    /**
     * Поля, доступные для массового заполнения (Mass Assignment).
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'value',
    ];

    /**
     * Товар, которому принадлежит атрибут.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
