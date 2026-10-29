<?php

declare(strict_types=1);

namespace App\Messaging;

final class PaymentFailed
{
    public function __construct(
        public string $sagaId,
    ) {}
}
