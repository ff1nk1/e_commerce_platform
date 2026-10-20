<?php

namespace Tests\Concerns;

trait RequiresStripe
{
    protected function requireStripeSecret(): string
    {
        // config() default is unused when the key exists but is ""; fall back explicitly.
        $secret = (string) config('services.stripe.secret');
        if ($secret === '' || str_contains($secret, 'dummy')) {
            $secret = $this->envString('STRIPE_SECRET', '');
        }
        if ($secret === '' || str_contains($secret, 'dummy')) {
            $this->fail('STRIPE_SECRET is not configured for integration tests.');
        }

        return $secret;
    }

    protected function requireStripeWebhookSecret(): string
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            $secret = $this->envString('STRIPE_WEBHOOK_SECRET', '');
        }
        if ($secret === '') {
            $this->fail('STRIPE_WEBHOOK_SECRET is not configured for integration tests.');
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function signStripeWebhookPayload(array $event): array
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $secret = $this->requireStripeWebhookSecret();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return [
            'payload' => $payload,
            'header' => 't='.$timestamp.',v1='.$signature,
        ];
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}
