<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'Customer', 'slug' => 'customer'],
            ['name' => 'Analyst',  'slug' => 'analyst'],
            ['name' => 'Admin',    'slug' => 'admin'],
        ];

        foreach ($roles as $role) {
            Role::query()->firstOrCreate(['slug' => $role['slug']], ['name' => $role['name']]);
        }
    }
}
