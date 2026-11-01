<?php

declare(strict_types=1);

namespace App\DTO\Search;

final readonly class AutocompleteQuery
{
    public function __construct(
        public ?string $q,
        public int $limit = 8,
        public string|int $fuzziness = 'AUTO',
    ) {}
}
