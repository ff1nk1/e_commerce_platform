<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public string $role_slug = '';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
