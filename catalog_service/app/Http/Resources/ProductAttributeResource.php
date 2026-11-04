<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public attribute view (name/value pairs of a product).
 *
 * @mixin ProductAttribute
 */
final class ProductAttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'value' => (string) $this->value,
        ];
    }
}
