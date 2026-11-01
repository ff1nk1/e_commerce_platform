<?php

declare(strict_types=1);

namespace App\DTO\Auth;

final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public ?string $number = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $number = $data['number'] ?? null;

        return new self(
            name: (string) $data['name'],
            email: (string) $data['email'],
            password: (string) $data['password'],
            number: is_string($number) && $number !== '' ? $number : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistenceArray(int $roleId): array
    {
        $payload = [
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'role_id' => $roleId,
        ];

        if ($this->number !== null) {
            $payload['number'] = $this->number;
        }

        return $payload;
    }
}
