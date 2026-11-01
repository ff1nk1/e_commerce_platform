<?php

declare(strict_types=1);

namespace App\DTO\Search;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class AutocompleteResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array{id: int, name: string, price: float|null, image_url: string|null}>  $suggestions
     */
    public function __construct(
        public array $suggestions,
    ) {}

    /**
     * @return array{suggestions: list<array{id: int, name: string, price: float|null, image_url: string|null}>}
     */
    public function toArray(): array
    {
        return ['suggestions' => $this->suggestions];
    }

    /**
     * @return array{suggestions: list<array{id: int, name: string, price: float|null, image_url: string|null}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
