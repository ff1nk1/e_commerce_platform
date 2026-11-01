<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Models\User;

final readonly class GatewayIdentity
{
    public function __construct(
        public int $id,
        public string $role,
        public string $name = '',
        public string $email = '',
    ) {}

    public static function fromUser(User $user): self
    {
        $user->loadMissing('role');

        return new self(
            id: (int) $user->id,
            role: (string) ($user->role->slug ?? ''),
            name: (string) ($user->name ?? ''),
            email: (string) ($user->email ?? ''),
        );
    }

    /**
     * Identity headers trusted by internal services behind the gateway.
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            'X-User-Id' => (string) $this->id,
            'X-User-Role' => $this->role,
        ];

        if ($this->name !== '') {
            $headers['X-User-Name'] = $this->name;
        }

        if ($this->email !== '') {
            $headers['X-User-Email'] = $this->email;
        }

        return $headers;
    }
}
