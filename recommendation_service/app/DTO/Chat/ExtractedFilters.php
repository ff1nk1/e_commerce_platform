<?php

declare(strict_types=1);

namespace App\DTO\Chat;

/**
 * Typed wrapper for the `extracted_filters` payload (filters + relaxation labels).
 */
final readonly class ExtractedFilters
{
    /**
     * @param  list<string>  $relaxed
     */
    public function __construct(
        public SearchFilters $filters,
        public array $relaxed = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->filters->toArray();
        if ($this->relaxed !== []) {
            $payload['relaxed'] = $this->relaxed;
        }

        return $payload;
    }
}
