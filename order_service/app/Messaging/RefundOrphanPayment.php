<?php

declare(strict_types=1);

namespace App\Messaging;

final class RefundOrphanPayment
{
    public function __construct(
        public int $paymentId,
        public int $orderId,
        public string $paymentIntentId,
    ) {}
}
