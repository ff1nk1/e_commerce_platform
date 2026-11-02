<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Auth\LoginCredentials;
use App\DTO\Auth\TokenPair;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\LoginUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class AnalystReadOnlyPerimeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->select((int) config('database.redis.default.database', 15));
        Redis::flushdb();
        Cache::flush();

        Role::factory()->create(['name' => 'Admin', 'slug' => 'admin']);
        Role::factory()->create(['name' => 'Analyst', 'slug' => 'analyst']);
    }

    public function test_analyst_cannot_ship_an_order(): void
    {
        $tokens = $this->tokensForRole('analyst', 'analyst-ship@example.com');

        $this->withToken($tokens->accessToken)
            ->postJson('/api/admin/orders/5/ship')
            ->assertForbidden();
    }

    public function test_analyst_can_read_analytics(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200, ['Content-Type' => 'application/json']),
        ]);

        $tokens = $this->tokensForRole('analyst', 'analyst-read@example.com');

        $this->withToken($tokens->accessToken)
            ->getJson('/api/analytics/users')
            ->assertOk();
    }

    public function test_admin_can_still_ship_an_order(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'shipped'], 200, ['Content-Type' => 'application/json']),
        ]);

        $tokens = $this->tokensForRole('admin', 'admin-ship@example.com');

        $this->withToken($tokens->accessToken)
            ->postJson('/api/admin/orders/5/ship')
            ->assertOk();
    }

    public function test_analyst_non_get_request_does_not_reach_upstream(): void
    {
        Http::fake();

        $tokens = $this->tokensForRole('analyst', 'analyst-upstream@example.com');

        $this->withToken($tokens->accessToken)
            ->postJson('/api/admin/orders/5/ship')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    private function tokensForRole(string $slug, string $email): TokenPair
    {
        $role = Role::query()->where('slug', $slug)->firstOrFail();

        User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        return resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => $email,
            'password' => 'password123',
        ]));
    }
}
