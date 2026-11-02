<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Payments\CheckoutLineItems;
use App\DTO\Payments\CheckoutSessionParams;
use App\DTO\Payments\CheckoutUrls;
use App\Infrastructure\Stripe\StripeClient;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Fakes\StripeRecordingHttpClient;
use Tests\TestCase;

final class StripeClientIdempotencyTest extends TestCase
{
    private ?ClientInterface $previousHttpClient = null;

    protected function tearDown(): void
    {
        if ($this->previousHttpClient instanceof ClientInterface) {
            ApiRequestor::setHttpClient($this->previousHttpClient);
            $this->previousHttpClient = null;
        }

        parent::tearDown();
    }

    public function test_refund_sends_idempotency_key_header(): void
    {
        $http = $this->fakeStripeHttp(['id' => 're_1', 'object' => 'refund']);

        $client = new StripeClient;
        $client->refundPaymentIntent('pi_1', null, 'order:9:refund:pi_1');

        $this->assertNotNull($http->lastRequest);
        $this->assertSame('post', $http->lastRequest['method']);
        $this->assertStringContainsString('/v1/refunds', $http->lastRequest['url']);
        $this->assertContains('Idempotency-Key: order:9:refund:pi_1', $http->lastRequest['headers']);
    }

    public function test_mutating_request_without_key_is_rejected(): void
    {
        $this->fakeStripeHttp(['id' => 'cs_1']);

        $client = new StripeClient;

        $this->expectException(RuntimeException::class);
        $client->createCheckoutSession(
            new CheckoutSessionParams(
                urls: new CheckoutUrls('https://shop.test/ok', 'https://shop.test/cancel'),
                mode: 'payment',
                clientReferenceId: '1',
                orderId: 1,
                paymentId: 1,
                expiresAt: 0,
                lineItems: new CheckoutLineItems([]),
            ),
            ''
        );
    }

    /**
     * Swap the SDK transport for a recording stub.
     *
     * @param  array<string, mixed>  $response
     */
    private function fakeStripeHttp(array $response): StripeRecordingHttpClient
    {
        $this->previousHttpClient = ApiRequestor::httpClient();

        $stub = new StripeRecordingHttpClient($response);
        ApiRequestor::setHttpClient($stub);

        return $stub;
    }
}
