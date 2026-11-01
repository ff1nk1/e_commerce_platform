<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\DTO\Payments\StripeCheckoutSession;
use App\Models\Payment;

final class StripePaymentResolver
{
    /**
     * Single locked Payment load for a Stripe checkout session payload.
     */
    public static function lockForSession(StripeCheckoutSession $session): ?Payment
    {
        $sessionId = $session->id;
        $paymentId = $session->metadataPaymentId;

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
