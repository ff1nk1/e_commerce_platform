<?php

namespace Tests\Unit;

use App\Services\Gateway\ProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxyForwardTimeoutTest extends TestCase
{
    public function test_forward_accepts_custom_timeout(): void
    {
        Http::fake([
            'http://recommendation.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $request = Request::create('/api/recommendations/chat', 'POST', [
            'message' => 'hello',
        ]);

        $response = app(ProxyService::class)->forward(
            $request,
            'http://recommendation.test',
            '/api/recommendations/chat',
            null,
            120,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', $response->getContent());

        Http::assertSent(function ($httpRequest) {
            return str_contains($httpRequest->url(), '/api/recommendations/chat');
        });
    }
}
