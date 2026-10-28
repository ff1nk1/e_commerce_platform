<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Isolates unit/feature tests from real STRIPE_* in .env / Docker env_file.
 * Local HMAC only — does not call Stripe HTTP.
 */
trait UsesFakeStripe
{
    protected const FAKE_STRIPE_SECRET = 'sk_test_dummy';

    protected const FAKE_WEBHOOK_SECRET = 'whsec_test_secret';

    protected function useFakeStripe(): void
    {
        config([
            'services.stripe.secret' => self::FAKE_STRIPE_SECRET,
            'services.stripe.webhook_secret' => self::FAKE_WEBHOOK_SECRET,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{payload: string, header: string}
     */
    protected function signFakeStripeWebhook(array $event): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();
        $secret = (string) config('services.stripe.webhook_secret', self::FAKE_WEBHOOK_SECRET);
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return [
            'payload' => $payload,
            'header' => 't='.$timestamp.',v1='.$signature,
        ];
    }
}
