<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class GatewayRateLimitersTest extends TestCase
{
    public function test_login_limiter_is_keyed_by_email_and_ip(): void
    {
        $limiter = RateLimiter::limiter('login');
        $this->assertNotNull($limiter);

        $request = Request::create('/api/login', 'POST', ['email' => 'User@Example.com']);
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        /** @var Limit $limit */
        $limit = $limiter($request);

        $this->assertSame(5, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('user@example.com|203.0.113.7', $limit->key);
    }

    public function test_login_limiter_distinguishes_ips_for_same_email(): void
    {
        $limiter = RateLimiter::limiter('login');
        $this->assertNotNull($limiter);

        $first = Request::create('/api/login', 'POST', ['email' => 'user@example.com']);
        $first->server->set('REMOTE_ADDR', '203.0.113.7');

        $second = Request::create('/api/login', 'POST', ['email' => 'user@example.com']);
        $second->server->set('REMOTE_ADDR', '203.0.113.8');

        $this->assertNotSame($limiter($first)->key, $limiter($second)->key);
    }

    public function test_search_limiter_is_keyed_by_ip(): void
    {
        $limiter = RateLimiter::limiter('search');
        $this->assertNotNull($limiter);

        $request = Request::create('/api/search', 'GET');
        $request->server->set('REMOTE_ADDR', '198.51.100.4');

        /** @var Limit $limit */
        $limit = $limiter($request);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('198.51.100.4', $limit->key);
    }
}
