<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Llm\Exceptions\LlmException;
use App\Models\ProductCatalog;
use App\Services\Catalog\ProductEmbedder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Regenerate embeddings for catalog products that have none')]
#[Signature('embeddings:sweep-missing')]
final class SweepMissingEmbeddings extends Command
{
    public function handle(ProductEmbedder $embedder): int
    {
        $embedded = 0;
        $failed = 0;

        ProductCatalog::query()
            ->whereNull('embedding')
            ->orderBy('id')
            ->chunkById(50, function ($products) use ($embedder, &$embedded, &$failed): void {
                foreach ($products as $product) {
                    /** @var ProductCatalog $product */
                    try {
                        $embedder->embed($product);
                        $embedded++;
                    } catch (LlmException) {
                        $failed++;
                    }
                }
            });

        $this->info("Embedded {$embedded} product(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
