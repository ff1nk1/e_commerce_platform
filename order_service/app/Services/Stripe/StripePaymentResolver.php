<?php

namespace App\Services\Stripe;

use App\Models\Payment;

final class StripePaymentResolver
{
    /**
     * Single locked Payment load for a Stripe checkout session payload.
     *
     * @param  array<string, mixed>  $session
     */
    public static function lockForSession(array $session): ?Payment
    {
        $sessionId = $session['id'] ?? null;
        $paymentId = $session['metadata']['payment_id'] ?? null;

        if (is_numeric($paymentId)) {
            $payment = Payment::query()->lockForUpdate()->find((int) $paymentId);
            if ($payment !== null) {
                return $payment;
            }
        }

        if (is_string($sessionId) && $sessionId !== '') {
            return Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }
}
