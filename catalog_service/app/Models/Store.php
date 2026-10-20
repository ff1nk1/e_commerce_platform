<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    /**
     * Поля, доступные для массового заполнения (Mass Assignment).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'contacts',
    ];

    /**
     * Приведение типов атрибутов.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contacts' => 'array',
    ];

    /**
     * Товары, принадлежащие данному магазину.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
