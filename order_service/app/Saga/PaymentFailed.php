<?php

declare(strict_types=1);

namespace App\Saga;

final class PaymentFailed
{
    public function __construct(
        public string $sagaId,
    ) {}
}
