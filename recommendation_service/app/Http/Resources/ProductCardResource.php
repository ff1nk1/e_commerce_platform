<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTO\Catalog\ProductCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialization boundary for product cards. Guarantees only the fields exposed
 * by ProductCard (never the embedding vector) reach the HTTP response.
 *
 * @property-read ProductCard $resource
 */
final class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
