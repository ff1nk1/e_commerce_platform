<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public product view. Whitelisted fields only — relations are emitted when
 * the caller eager-loaded them; nothing internal leaks through `toArray()`.
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'store_id' => (int) $this->store_id,
            'category_id' => $this->category_id !== null ? (int) $this->category_id : null,
            'name' => (string) $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'price' => (string) $this->price,
            'is_published' => (bool) $this->is_published,
            'store' => new StoreResource($this->whenLoaded('store')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'attributes' => ProductAttributeResource::collection($this->whenLoaded('attributes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
