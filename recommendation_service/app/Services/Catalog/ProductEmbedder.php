<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\LlmClient;
use App\Enums\EmbedTask;
use App\Infrastructure\Llm\Exceptions\LlmException;
use App\Models\ProductCatalog;
use App\Support\PgVectorLiteral;
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
            $text = $this->textBuilder->fromModel($product);
            $vector = $this->llm->embed($text, EmbedTask::RetrievalDocument);
            $literal = PgVectorLiteral::from($vector);

            DB::statement(
                'UPDATE product_catalog SET embedding = ?::vector, updated_at = ? WHERE id = ?',
                [$literal, now(), $product->id]
            );
        } catch (LlmException $e) {
            Log::error('ProductIndexer: failed to generate embedding', [
                'product_id' => $product->product_id,
                'status' => $e->statusCode(),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
