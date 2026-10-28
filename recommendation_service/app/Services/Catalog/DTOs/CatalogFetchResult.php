<?php

declare(strict_types=1);

namespace App\Services\Catalog\DTOs;

final readonly class CatalogFetchResult
{
    /**
     * @param  array<int, array<string, mixed>>  $products  Successful 2xx payloads keyed by product id
     * @param  list<int>  $notFound  Explicit 404s — treat as deleted/unpublished
     * @param  list<int>  $failed  Transport / 5xx / auth failures — keep local hits
     */
    public function __construct(
        public array $products = [],
        public array $notFound = [],
        public array $failed = [],
    ) {}
}
