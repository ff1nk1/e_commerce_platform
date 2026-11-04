<?php

declare(strict_types=1);

namespace App\Saga;

final class PaymentCaptured
{
    public function __construct(
        public string $sagaId,
    ) {}
}
