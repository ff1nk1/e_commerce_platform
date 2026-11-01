<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\DTO\Payments\StripeCheckoutSession;
use App\Models\Payment;
use App\ValueObjects\Money;

final class StripeSessionMoney
{
    public static function matchesExpected(
        Payment $payment,
        StripeCheckoutSession $session,
        string $currency = StripeLineItems::CURRENCY,
    ): bool {
        $expectedMinor = Money::of((string) $payment->amount)->toMinorUnits();
        $actualMinor = $session->amountTotal !== null ? (int) $session->amountTotal : null;
        $expectedCurrency = strtolower($currency);
        $sessionCurrency = strtolower((string) ($session->currency ?? ''));

        return $actualMinor !== null
            && $actualMinor === $expectedMinor
            && $sessionCurrency === $expectedCurrency;
    }

    /**
     * @return array{expected_minor: int, actual_minor: int|null, currency: string}
     */
    public static function compareDetails(Payment $payment, StripeCheckoutSession $session): array
    {
        $sessionCurrency = strtolower((string) ($session->currency ?? ''));

        return [
            'expected_minor' => Money::of((string) $payment->amount)->toMinorUnits(),
            'actual_minor' => $session->amountTotal !== null ? (int) $session->amountTotal : null,
            'currency' => $sessionCurrency,
        ];
    }
}
