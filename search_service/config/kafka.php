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

    'allow.auto.create.topics' => true,

    'consumer_group_id' => env('KAFKA_CONSUMER_GROUP_ID', 'group'),

    'consumer_timeout_ms' => env('KAFKA_CONSUMER_DEFAULT_TIMEOUT', 2000),

    'offset_reset' => env('KAFKA_OFFSET_RESET', 'latest'),

    'auto_commit' => env('KAFKA_AUTO_COMMIT', true),

    'sleep_on_error' => env('KAFKA_ERROR_SLEEP', 5),

    'partition' => env('KAFKA_PARTITION', 0),

    'compression' => env('KAFKA_COMPRESSION_TYPE', 'snappy'),

    'debug' => env('KAFKA_DEBUG', false),

    'flush_retry_sleep_in_ms' => 100,

    'flush_retries' => 10,

    'flush_timeout_in_ms' => 1000,

    'cache_driver' => env('KAFKA_CACHE_DRIVER', env('CACHE_DRIVER', env('CACHE_STORE', 'array'))),

    'message_id_key' => env('MESSAGE_ID_KEY', 'laravel-kafka::message-id'),

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
