<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Domain\Money;
use App\Models\Order;

final class StripeLineItems
{
    public const CURRENCY = 'rub';

    /**
     * @return list<array{
     *     quantity: int,
     *     price_data: array{
     *         currency: string,
     *         unit_amount: int,
     *         product_data: array{name: string}
     *     }
     * }>
     */
    public static function fromOrder(Order $order): array
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $snapshotName = $item->productSnapshot !== null ? $item->productSnapshot->name : null;
            $displayName = $snapshotName ?? ('Product #'.$item->product_id);
            $unitAmountMinor = Money::of((string) $item->unit_price)->toMinorUnits();

            $lineItems[] = [
                'quantity' => (int) $item->quantity,
                'price_data' => [
                    'currency' => self::CURRENCY,
                    'unit_amount' => $unitAmountMinor,
                    'product_data' => [
                        'name' => $displayName,
                    ],
                ],
            ];
        }

        return $lineItems;
    }
}
