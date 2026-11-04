<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stock view. Exposes the derived `available` quantity alongside the physical
 * counters so admin UIs keep working.
 *
 * @mixin Stock
 */
final class StockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->product_id,
            'store_id' => (int) $this->store_id,
            'quantity' => (int) $this->quantity,
            'reserved' => (int) $this->reserved,
            'available' => (int) ($this->quantity - $this->reserved),
            'product' => new ProductResource($this->whenLoaded('product')),
            'store' => new StoreResource($this->whenLoaded('store')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
