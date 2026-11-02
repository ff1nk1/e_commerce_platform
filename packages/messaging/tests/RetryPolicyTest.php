<?php

declare(strict_types=1);

namespace Shop\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Shop\Messaging\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function test_backoff_grows_exponentially_and_caps(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, baseDelayMs: 1000, maxDelayMs: 5000);

        $this->assertSame(1000, $policy->backoffMs(1));
        $this->assertSame(2000, $policy->backoffMs(2));
        $this->assertSame(4000, $policy->backoffMs(3));
        $this->assertSame(5000, $policy->backoffMs(4));
        $this->assertSame(5000, $policy->backoffMs(10));
    }

    public function test_from_config_reads_overrides(): void
    {
        $policy = RetryPolicy::fromConfig([
            'max_attempts' => 7,
            'retry_base_ms' => 250,
            'retry_max_ms' => 9000,
            'dlq_publish_attempts' => 4,
        ]);

        $this->assertSame(7, $policy->maxAttempts);
        $this->assertSame(250, $policy->baseDelayMs);
        $this->assertSame(9000, $policy->maxDelayMs);
        $this->assertSame(4, $policy->dlqAttempts);
    }
}
