<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\ReindexProductsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Regenerates embeddings for the whole catalog off the request path.
 *
 * Dispatched by POST /api/recommendations/reindex; run by queue:work.
 * Reindexing the full catalog over batched LLM calls can take minutes.
 */
#[Tries(3)]
#[Timeout(900)]
final class ReindexCatalogEmbeddings implements ShouldQueue
{
    use Queueable;

    public function handle(ReindexProductsService $service): void
    {
        $indexed = $service->handle();

        Log::info('ReindexCatalogEmbeddings: completed', ['indexed' => $indexed]);
    }
}
