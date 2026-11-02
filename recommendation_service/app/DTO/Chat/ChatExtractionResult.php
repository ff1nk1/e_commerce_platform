<?php

declare(strict_types=1);

namespace App\DTO\Chat;

/**
 * Result of a combined filter-extraction + query-embedding LLM call.
 */
final readonly class ChatExtractionResult
{
    /**
     * @param  list<float>  $embedding
     */
    public function __construct(
        public SearchFilters $filters,
        public array $embedding,
    ) {}

    /**
     * @return array{0: SearchFilters, 1: list<float>}
     */
    public function toArray(): array
    {
        return [$this->filters, $this->embedding];
    }
}
