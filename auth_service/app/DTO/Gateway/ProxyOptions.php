<?php

declare(strict_types=1);

namespace App\DTO\Gateway;

use App\DTO\Auth\GatewayIdentity;
use App\Enums\ProxyMode;

final readonly class ProxyOptions
{
    public const DEFAULT_TIMEOUT = 30;

    public function __construct(
        public ProxyMode $mode = ProxyMode::Json,
        public ?GatewayIdentity $identity = null,
        public int $timeout = self::DEFAULT_TIMEOUT,
    ) {}
}
