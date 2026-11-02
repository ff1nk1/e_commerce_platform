<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductCatalog;
use Illuminate\Support\Collection;

final class ReindexProductsService
{
    public function __construct(
        private readonly ProductEmbedder $embedder,
    ) {}

    public function handle(): int
    {
        $batchSize = max(1, (int) config('recommendations.embeddings_batch_size', 50));

        $count = 0;
        ProductCatalog::query()->orderBy('id')->chunkById($batchSize, function ($rows) use (&$count): void {
            /** @var Collection<int, ProductCatalog> $rows */
            $count += $this->embedder->embedMany($rows);
        });

        return $count;
    }
}
