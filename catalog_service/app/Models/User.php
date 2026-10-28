<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
])]
#[Hidden([
    'password',
    'remember_token',
])]
final class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public string $role_slug = '';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function fromGatewayHeaders(
        int $userId,
        string $role,
        ?string $name = null,
        ?string $email = null,
    ): self {
        $hasDisplayName = is_string($name) && $name !== '';
        $hasDisplayEmail = is_string($email) && $email !== '';
        $displayName = $hasDisplayName ? $name : 'Admin #'.$userId;
        $displayEmail = $hasDisplayEmail ? $email : 'admin-'.$userId.'@gateway.local';

        $user = new self([
            'name' => $displayName,
            'email' => $displayEmail,
        ]);
        $user->id = $userId;
        $user->role_slug = $role;
        $user->exists = true;

        return $user;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role_slug === 'admin';
    }
}
