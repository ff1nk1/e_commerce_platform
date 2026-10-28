<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
final class ProductCatalog extends Model
{
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    public function toCardArray(): array
    {
        return [
            'product_id' => $this->product_id,
            'store_id' => $this->store_id,
            'store_name' => $this->store_name,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category_name' => $this->category_name,
            'image_url' => $this->image_url,
            'price' => (float) $this->price,
            'attributes' => $this->attributes,
            'is_published' => (bool) $this->is_published,
            'in_stock' => (bool) $this->in_stock,
            'path' => '/products/'.$this->product_id,
        ];
    }

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
