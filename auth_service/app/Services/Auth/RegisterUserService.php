<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTO\Auth\RegisterUserData;
use App\Events\UserRegistered;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RegisterUserService
{
    public function handle(RegisterUserData $data): User
    {
        $roleId = (int) Role::query()->where('slug', 'customer')->valueOrFail('id');

        return DB::transaction(function () use ($data, $roleId): User {
            $user = User::query()->create($data->toPersistenceArray($roleId));
            event(new UserRegistered($user));

            return $user;
        });
    }
}
