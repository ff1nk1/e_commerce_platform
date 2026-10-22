<?php

namespace App\Services\Stripe;

use App\Contracts\PaymentGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stripe\Webhook;
use UnexpectedValueException;

// stripe listen --forward-to https://app.localhost/api/webhooks/stripe --skip-verify
/**
 * Thin Stripe API client (secret-key auth). Prefer this over shipping JWT to Stripe.
 * Uses stripe/stripe-php for webhook verification when installed; falls back to HMAC.
 */
class StripeClient implements PaymentGateway
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function createCheckoutSession(array $params): array
    {
        return $this->request('post', 'checkout/sessions', $params);
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->request('get', 'checkout/sessions/'.$sessionId);
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return $this->request('post', 'checkout/sessions/'.$sessionId.'/expire');
    }

    public function refundPaymentIntent(string $paymentIntentId, ?int $amountMinor = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amountMinor !== null) {
            $params['amount'] = $amountMinor;
        }

        return $this->request('post', 'refunds', $params);
    }

    /**
     * @return array{id: string, type: string, data: array{object: array<string, mixed>}}
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        if (class_exists(Webhook::class)) {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);

            return $event->toArray();
        }

        $this->verifySignature($payload, $signatureHeader, $secret);

        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! isset($decoded['type'], $decoded['data']['object'])) {
            throw new UnexpectedValueException('Invalid Stripe webhook payload.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }

        $pending = Http::withToken($secret)
            ->acceptJson()
            ->asForm()
            ->timeout(30);

        try {
            $response = $method === 'get'
                ? $pending->get('https://api.stripe.com/v1/'.$path, $params)
                : $pending->post('https://api.stripe.com/v1/'.$path, $params);

            $response->throw();
        } catch (RequestException $e) {
            $body = $e->response->json();
            $stripeErrorMessage = $body['error']['message'] ?? $e->getMessage();

            throw new RuntimeException('Stripe API error: '.$stripeErrorMessage, previous: $e);
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    private function verifySignature(string $payload, string $header, string $secret): void
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (! str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            if ($key === 't') {
                $timestamp = $value;
            }
            if ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            throw new UnexpectedValueException('Unable to extract timestamp and signatures from header');
        }

        $eventTime = Carbon::createFromTimestamp((int) $timestamp);
        if ($eventTime->diffInSeconds(now(), absolute: true) > self::SIGNATURE_TOLERANCE_SECONDS) {
            throw new UnexpectedValueException('Timestamp outside the tolerance zone');
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new UnexpectedValueException('No signatures found matching the expected signature for payload');
    }
}
