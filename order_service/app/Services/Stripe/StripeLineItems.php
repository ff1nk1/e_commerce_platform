<?php

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
            $name = ($item->productSnapshot !== null ? $item->productSnapshot->name : null)
                ?? ('Product #'.$item->product_id);

            $lineItems[] = [
                'quantity' => (int) $item->quantity,
                'price_data' => [
                    'currency' => self::CURRENCY,
                    'unit_amount' => Money::of((string) $item->unit_price)->toMinorUnits(),
                    'product_data' => [
                        'name' => $name,
                    ],
                ],
            ];
        }

        return $lineItems;
    }
}
