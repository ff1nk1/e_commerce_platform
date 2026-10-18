<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vector relevance
    |--------------------------------------------------------------------------
    |
    | Cosine distance (pgvector <=>). Lower is closer. Hits with distance
    | greater than this threshold are dropped before answering.
    |
    */
    'max_cosine_distance' => (float) env('RECOMMENDATION_MAX_COSINE_DISTANCE', 0.75),

    /*
    |--------------------------------------------------------------------------
    | Default stock filter
    |--------------------------------------------------------------------------
    |
    | When extract/client leave in_stock unset, require in-stock products.
    |
    */
    'require_in_stock' => filter_var(
        env('RECOMMENDATION_REQUIRE_IN_STOCK', true),
        FILTER_VALIDATE_BOOL
    ),

    'catalog' => [
        'base_url' => rtrim((string) env('CATALOG_SERVICE_URL', 'http://catalog_service_app:8002'), '/'),
        'timeout' => (int) env('RECOMMENDATION_CATALOG_TIMEOUT', 5),
    ],

];
