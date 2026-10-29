<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Stripe\StripeClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class StripeClientIdempotencyTest extends TestCase
{
    public function test_refund_sends_idempotency_key_header(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 're_1'], 200),
        ]);

        $client = new StripeClient;
        $client->refundPaymentIntent('pi_1', null, 'order:9:refund:pi_1');

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/v1/refunds')
                && $request->hasHeader('Idempotency-Key', 'order:9:refund:pi_1');
        });
    }

    public function test_mutating_request_without_key_is_rejected(): void
    {
        $client = new StripeClient;

        $this->expectException(RuntimeException::class);
        $client->createCheckoutSession(['mode' => 'payment'], '');
    }
}
