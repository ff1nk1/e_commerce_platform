<?php

declare(strict_types=1);

namespace App\DTO\Events;

use App\Models\User;

final readonly class UserRegisteredPayload
{
    public const EVENT_TYPE = 'user.registered';

    public function __construct(
        public int $userId,
        public string $email,
        public string $name,
        public string $registeredAt,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self(
            userId: (int) $user->id,
            email: (string) $user->email,
            name: (string) $user->name,
            registeredAt: $user->created_at?->toIso8601String() ?? now()->toIso8601String(),
        );
    }

    /**
     * @return array{user_id: int, email: string, name: string, registered_at: string, event_type: string, event: string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'email' => $this->email,
            'name' => $this->name,
            'registered_at' => $this->registeredAt,
            'event_type' => self::EVENT_TYPE,
            'event' => self::EVENT_TYPE,
        ];
    }
}
