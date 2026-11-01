<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Infrastructure\Llm\Exceptions\LlmException;
use App\Models\ProductCatalog;

final class ReindexProductsService
{
    public function __construct(
        private readonly ProductEmbedder $embedder,
    ) {}

    public function handle(): int
    {
        $count = 0;
        ProductCatalog::query()->orderBy('id')->chunkById(50, function ($rows) use (&$count): void {
            foreach ($rows as $product) {
                /** @var ProductCatalog $product */
                try {
                    $this->embedder->embed($product);
                    $count++;
                } catch (LlmException) {
                    // Logged in embed. Leave embedding null and continue.
                }
            }
        });

        return $count;
    }
}
