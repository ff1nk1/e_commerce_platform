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

    /*
     | Inbound consumer retries and dead-letter publishing, read by
     | Shop\Messaging\RetryPolicy. The adapter commits the source offset only
     | after the inbox write or after the DLQ broker ack (acks=all).
     */
    'consumer' => [
        'max_attempts' => (int) env('KAFKA_CONSUMER_MAX_ATTEMPTS', 5),
        'retry_base_ms' => (int) env('KAFKA_CONSUMER_RETRY_BASE_MS', 1000),
        'retry_max_ms' => (int) env('KAFKA_CONSUMER_RETRY_MAX_MS', 30000),
        'dlq_publish_attempts' => (int) env('KAFKA_CONSUMER_DLQ_PUBLISH_ATTEMPTS', 3),
        'dlq_suffix' => env('KAFKA_DLQ_SUFFIX', '.dlq'),
    ],
];
