<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\LlmClient;
use App\Enums\EmbedTask;
use App\Infrastructure\Llm\Exceptions\LlmException;
use App\Models\ProductCatalog;
use App\Support\PgVectorLiteral;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProductEmbedder
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ProductTextBuilder $textBuilder,
    ) {}

    public function embed(ProductCatalog $product): void
    {
        try {
            $vector = $this->llm->embed($this->textBuilder->fromModel($product), EmbedTask::RetrievalDocument);
            $this->store($product, $vector);
        } catch (LlmException $e) {
            Log::error('ProductIndexer: failed to generate embedding', [
                'product_id' => $product->product_id,
                'status' => $e->statusCode(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Embed a whole batch of products with as few LLM round-trips as possible.
     *
     * Returns the number of products whose embedding was written. A batch HTTP
     * failure (Gemini rejects the whole request when one input is bad) falls back
     * to per-product embedding so one bad document cannot block the rest.
     *
     * @param  Collection<int, ProductCatalog>  $products
     */
    public function embedMany(Collection $products): int
    {
        $products = $products->values();
        if ($products->isEmpty()) {
            return 0;
        }

        $texts = [];
        foreach ($products as $product) {
            $texts[] = $this->textBuilder->fromModel($product);
        }

        try {
            /** @var list<list<float>> $vectors */
            $vectors = $this->llm->embedBatch($texts, EmbedTask::RetrievalDocument);
        } catch (LlmException $e) {
            Log::warning('ProductIndexer: batch embed failed, falling back to per-product', [
                'count' => count($texts),
                'status' => $e->statusCode(),
                'message' => $e->getMessage(),
            ]);

            return $this->embedIndividually($products);
        }

        $written = 0;
        foreach ($products as $index => $product) {
            $vector = $vectors[$index] ?? null;
            if (! is_array($vector) || $vector === []) {
                continue;
            }

            $this->store($product, $vector);
            $written++;
        }

        return $written;
    }

    /**
     * @param  Collection<int, ProductCatalog>  $products
     */
    private function embedIndividually(Collection $products): int
    {
        $written = 0;
        foreach ($products as $product) {
            try {
                $this->embed($product);
                $written++;
            } catch (LlmException) {
                // Already logged inside embed().
            }
        }

        return $written;
    }

    /**
     * @param  list<float>  $vector
     */
    private function store(ProductCatalog $product, array $vector): void
    {
        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector, updated_at = ? WHERE id = ?',
            [PgVectorLiteral::from($vector), now(), $product->id]
        );
    }
}
