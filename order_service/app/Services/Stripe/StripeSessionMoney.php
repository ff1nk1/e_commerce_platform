<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Domain\Money;
use App\Models\Payment;

final class StripeSessionMoney
{
    /**
     * @param  array<string, mixed>  $session
     */
    public static function matchesExpected(
        Payment $payment,
        array $session,
        string $currency = StripeLineItems::CURRENCY,
    ): bool {
        $expectedMinor = Money::of((string) $payment->amount)->toMinorUnits();
        $actualMinor = isset($session['amount_total']) ? (int) $session['amount_total'] : null;
        $expectedCurrency = strtolower($currency);
        $sessionCurrency = strtolower((string) ($session['currency'] ?? ''));

        return $actualMinor !== null
            && $actualMinor === $expectedMinor
            && $sessionCurrency === $expectedCurrency;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{expected_minor: int, actual_minor: int|null, currency: string}
     */
    public static function compareDetails(Payment $payment, array $session): array
    {
        $sessionCurrency = strtolower((string) ($session['currency'] ?? ''));

        return [
            'expected_minor' => Money::of((string) $payment->amount)->toMinorUnits(),
            'actual_minor' => isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            'currency' => $sessionCurrency,
        ];
    }
}
