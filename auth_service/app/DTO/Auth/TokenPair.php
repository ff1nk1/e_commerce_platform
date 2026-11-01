<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Models\User;

final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public ?User $user = null,
    ) {}
}
