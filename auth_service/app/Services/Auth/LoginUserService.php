<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTO\Auth\LoginCredentials;
use App\DTO\Auth\TokenPair;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginUserService
{
    public function __construct(
        private SessionTokens $sessions,
    ) {}

    public function handle(LoginCredentials $credentials): TokenPair
    {
        $user = User::query()->where('email', $credentials->email)->first();

        if (! $user || ! Hash::check($credentials->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Неверный логин или пароль.'],
            ]);
        }

        return $this->sessions->issue($user);
    }
}
