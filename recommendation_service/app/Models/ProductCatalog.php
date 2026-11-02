<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'product_id',
    'store_id',
    'store_name',
    'name',
    'description',
    'category_id',
    'category_name',
    'image_url',
    'price',
    'attributes',
    'is_published',
    'in_stock',
    'embedding',
])]
#[Table(name: 'product_catalog')]
#[Hidden(['embedding'])]
final class ProductCatalog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_published' => 'boolean',
            'in_stock' => 'boolean',
            'product_id' => 'integer',
            'store_id' => 'integer',
            'category_id' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
