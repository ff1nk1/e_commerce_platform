<?php

declare(strict_types=1);

namespace App\Messaging;

final class OrderPlaced
{
    public function __construct(
        public string $sagaId,
    ) {}
}
