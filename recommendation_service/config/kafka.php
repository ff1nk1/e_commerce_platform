<?php

declare(strict_types=1);

return [
    'brokers' => env('KAFKA_BROKERS', 'kafka:29092'),
    'securityProtocol' => env('KAFKA_SECURITY_PROTOCOL', 'PLAINTEXT'),
    'sasl' => [
        'mechanisms' => env('KAFKA_MECHANISMS', 'PLAINTEXT'),
        'username' => env('KAFKA_USERNAME', null),
        'password' => env('KAFKA_PASSWORD', null),
    ],
    'consumer_group_id' => env('KAFKA_CONSUMER_GROUP_ID', 'recommendation-service'),
    'consumer_timeout_ms' => env('KAFKA_CONSUMER_DEFAULT_TIMEOUT', 2000),
    'offset_reset' => env('KAFKA_OFFSET_RESET', 'earliest'),
    'auto_commit' => env('KAFKA_AUTO_COMMIT', true),
    'cache_driver' => env('KAFKA_CACHE_DRIVER', 'array'),
    'debug' => env('KAFKA_DEBUG', false),

    'topics' => [
        'product_events' => env('KAFKA_TOPIC_PRODUCT_EVENTS', 'product.events'),
        'catalog_events' => env('KAFKA_TOPIC_CATALOG_EVENTS', 'catalog.events'),
    ],

    'consumer_groups' => [
        'products' => env('KAFKA_CONSUMER_GROUP_PRODUCTS', 'recommendation-service-products-v1'),
        'stock' => env('KAFKA_CONSUMER_GROUP_STOCK', 'recommendation-service-stock-v1'),
    ],
];
