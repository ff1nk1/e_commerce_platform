<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $image_url
 * @property bool $is_published
 * @property-read Category|null $category
 */
class Product extends Model
{
    use HasFactory;

    /**
     * Поля, доступные для массового заполнения (Mass Assignment).
     *
     * @var list<string>
     */
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'image_url',
        'price',
        'is_published',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'is_published' => 'boolean',
    ];

    /**
     * Магазин, которому принадлежит товар.
     *
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Категория, к которой относится товар.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductAttribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }
}
