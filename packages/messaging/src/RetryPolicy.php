<?php

declare(strict_types=1);

namespace Shop\Messaging;

/**
 * Tunes how the inbound adapter retries transient failures and how many times
 * it attempts to publish a dead letter before giving up.
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 5,
        public readonly int $baseDelayMs = 1000,
        public readonly int $maxDelayMs = 30000,
        public readonly int $dlqAttempts = 3,
    ) {}

    /**
     * Exponential backoff for the given 1-based attempt, capped at maxDelayMs.
     */
    public function backoffMs(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $delay = $this->baseDelayMs * (2 ** ($attempt - 1));

        return min($delay, $this->maxDelayMs);
    }

    public static function fromConfig(mixed $config = null): self
    {
        if (! is_array($config)) {
            $config = function_exists('config') ? config('kafka.consumer', []) : [];
        }
        if (! is_array($config)) {
            $config = [];
        }

        return new self(
            maxAttempts: max(1, (int) ($config['max_attempts'] ?? 5)),
            baseDelayMs: max(0, (int) ($config['retry_base_ms'] ?? 1000)),
            maxDelayMs: max(0, (int) ($config['retry_max_ms'] ?? 30000)),
            dlqAttempts: max(1, (int) ($config['dlq_publish_attempts'] ?? 3)),
        );
    }
}
