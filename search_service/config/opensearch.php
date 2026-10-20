<?php

return [
    'hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPENSEARCH_HOSTS', 'http://search_opensearch:9200'))
    ))),

    'indices' => [
        'products' => env('OPENSEARCH_PRODUCTS_INDEX', 'products'),
        'categories' => env('OPENSEARCH_CATEGORIES_INDEX', 'categories'),
    ],

    /*
    | Synonym lines for product search analyzer (OpenSearch synonym_graph).
    | Format: "term1, term2, term3" — one group per array element.
    */
    'synonyms' => array_values(array_filter(array_map(
        'trim',
        explode("\n", (string) env('OPENSEARCH_PRODUCT_SYNONYMS', implode("\n", [
            'ноутбук, ноутбуки, laptop, notebook',
            'телефон, смартфон, phone, smartphone',
            'наушники, headphones, headset',
        ])))
    ))),

    /*
    | Exact hit counting stops after this many documents (OpenSearch track_total_hits).
    | true = always exact (expensive); integer = bound cost for deep catalogs.
    */
    'track_total_hits' => (int) env('OPENSEARCH_TRACK_TOTAL_HITS', 10000),

    /*
    | When true, every index/update/delete waits for refresh (tests / ops).
    | Production default false — rely on near-real-time refresh interval.
    */
    'refresh_on_write' => filter_var(env('OPENSEARCH_REFRESH_ON_WRITE', false), FILTER_VALIDATE_BOOLEAN),

    /** HTTP rate limit for /api/search* (requests per minute per IP). */
    'throttle_per_minute' => (int) env('SEARCH_THROTTLE_PER_MINUTE', 60),

    /** When true, honor X-Skip-Analytics on search requests (loadtests only). */
    'allow_skip_analytics' => filter_var(env('ALLOW_SKIP_ANALYTICS', false), FILTER_VALIDATE_BOOLEAN),
];
