<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use App\DTO\Payments\CheckoutLineItem;
use App\DTO\Payments\CheckoutLineItems;
use App\Models\Order;
use App\ValueObjects\Money;

final class StripeLineItems
{
    public const CURRENCY = 'rub';

    public static function fromOrder(Order $order): CheckoutLineItems
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $snapshotName = $item->productSnapshot !== null ? $item->productSnapshot->name : null;
            $displayName = $snapshotName ?? ('Product #'.$item->product_id);

            $lineItems[] = new CheckoutLineItem(
                name: $displayName,
                unitAmountMinor: Money::of((string) $item->unit_price)->toMinorUnits(),
                quantity: (int) $item->quantity,
                currency: self::CURRENCY,
            );
        }

        return new CheckoutLineItems($lineItems);
    }
}
