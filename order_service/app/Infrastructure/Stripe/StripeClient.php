<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\Contracts\PaymentGateway;
use App\DTO\Payments\CheckoutSessionParams;
use App\DTO\Payments\RefundResult;
use App\DTO\Payments\StripeCheckoutSession;
use App\DTO\Payments\WebhookEvent;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Refund;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\Webhook;

// stripe listen --forward-to https://app.localhost/api/webhooks/stripe --skip-verify
/**
 * Stripe gateway backed by the official stripe/stripe-php SDK.
 *
 * Keeps the app-facing PaymentGateway contract and DTO shapes stable while the
 * SDK owns request signing, the Idempotency-Key header and error decoding.
 * Tests swap the transport through \Stripe\ApiRequestor::setHttpClient().
 */
class StripeClient implements PaymentGateway
{
    private ?StripeSdkClient $client = null;

    public function createCheckoutSession(CheckoutSessionParams $params, string $idempotencyKey): StripeCheckoutSession
    {
        $options = $this->mutatingOptions($idempotencyKey);

        $session = $this->run(
            fn (StripeSdkClient $client): Session => $client->checkout->sessions->create($params->toArray(), $options),
        );

        return StripeCheckoutSession::fromArray($session->toArray());
    }

    public function retrieveCheckoutSession(string $sessionId): StripeCheckoutSession
    {
        $session = $this->run(
            fn (StripeSdkClient $client): Session => $client->checkout->sessions->retrieve($sessionId),
        );

        return StripeCheckoutSession::fromArray($session->toArray());
    }

    public function expireCheckoutSession(string $sessionId, string $idempotencyKey): StripeCheckoutSession
    {
        $options = $this->mutatingOptions($idempotencyKey);

        $session = $this->run(
            fn (StripeSdkClient $client): Session => $client->checkout->sessions->expire($sessionId, [], $options),
        );

        return StripeCheckoutSession::fromArray($session->toArray());
    }

    public function refundPaymentIntent(string $paymentIntentId, ?int $amountMinor, string $idempotencyKey): RefundResult
    {
        $options = $this->mutatingOptions($idempotencyKey);

        $params = ['payment_intent' => $paymentIntentId];
        if ($amountMinor !== null) {
            $params['amount'] = $amountMinor;
        }

        $refund = $this->run(
            fn (StripeSdkClient $client): Refund => $client->refunds->create($params, $options),
        );

        return RefundResult::fromArray($refund->toArray());
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): WebhookEvent
    {
        $secret = (string) config('services.stripe.webhook_secret');
        throw_if($secret === '', RuntimeException::class, 'STRIPE_WEBHOOK_SECRET is not configured.');

        $event = Webhook::constructEvent($payload, $signatureHeader, $secret);

        return WebhookEvent::fromArray($event->toArray());
    }

    /**
     * The SDK requires an Idempotency-Key for mutating requests; the gateway
     * contract makes the caller supply one, so an empty key is a programming error.
     *
     * @return array{idempotency_key: string}
     */
    private function mutatingOptions(string $idempotencyKey): array
    {
        throw_if(
            $idempotencyKey === '',
            RuntimeException::class,
            'Stripe mutating request requires an Idempotency-Key.',
        );

        return ['idempotency_key' => $idempotencyKey];
    }

    /**
     * @template T
     *
     * @param  callable(StripeSdkClient): T  $callback
     * @return T
     */
    private function run(callable $callback): mixed
    {
        try {
            return $callback($this->client());
        } catch (ApiErrorException|UnexpectedValueException $e) {
            // ApiErrorException::getMessage() already carries Stripe's error message.
            throw new RuntimeException('Stripe API error: '.$e->getMessage(), $e->getCode(), previous: $e);
        }
    }

    private function client(): StripeSdkClient
    {
        if ($this->client instanceof StripeSdkClient) {
            return $this->client;
        }

        $secret = (string) config('services.stripe.secret');
        throw_if($secret === '', RuntimeException::class, 'STRIPE_SECRET is not configured.');

        return $this->client = new StripeSdkClient($secret);
    }
}
