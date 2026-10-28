<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRole->id,
                'number' => '+10000000000',
            ]
        );
    }
}
