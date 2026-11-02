<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'product_catalog_embedding_hnsw';

    public function up(): void
    {
        // Approximate nearest-neighbour index for the cosine (`<=>`) ranking used by
        // SearchCatalogProductsDetailedService. Without it every recommendation
        // query does a sequential scan + sort over the whole product_catalog.
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON product_catalog USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)',
            self::INDEX
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
