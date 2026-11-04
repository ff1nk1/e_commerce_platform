<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public order line. Whitelisted fields only — no internal ids beyond the
 * product reference the storefront already knows.
 *
 * @mixin OrderItem
 */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->productSnapshot;

        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->product_id,
            'quantity' => (int) $this->quantity,
            'unit_price' => (string) $this->unit_price,
            'product_snapshot' => $snapshot === null ? null : [
                'id' => (int) $snapshot->id,
                'name' => $snapshot->name,
                'price' => (string) $snapshot->price,
                'store_id' => $snapshot->store_id !== null ? (int) $snapshot->store_id : null,
                'store_name' => $snapshot->store_name,
                'category_id' => $snapshot->category_id !== null ? (int) $snapshot->category_id : null,
                'category_name' => $snapshot->category_name,
                'category_slug' => $snapshot->category_slug,
            ],
        ];
    }
}
