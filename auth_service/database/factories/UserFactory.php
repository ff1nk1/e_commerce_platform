<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password123'),
            'role_id' => 1,
            'number' => fake()->phoneNumber(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role_id' => 2,
        ]);
    }
}
