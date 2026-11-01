<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Models\User;

final readonly class InjectedGatewayUser
{
    public function __construct(
        public int $id,
        public ?string $email = null,
        public ?string $name = null,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            id: (int) $user->id,
            email: $user->email,
            name: $user->name,
        );
    }
}
