<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EnsureValidAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->select((int) config('database.redis.default.database', 15));
        Redis::flushdb();
        Cache::flush();

        Role::factory()->create([
            'name' => 'Customer',
            'slug' => 'customer',
        ]);
    }

    public function test_expired_access_token_is_silently_refreshed_via_cookie(): void
    {
        $role = Role::where('slug', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'silent@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $tokens = app(AuthService::class)->login([
            'email' => 'silent@example.com',
            'password' => 'password123',
        ]);

        $now = time();
        $expiredAccess = JWT::encode([
            'sub' => $user->id,
            'iat' => $now - 120,
            'exp' => $now - 60,
            'jti' => bin2hex(random_bytes(16)),
        ], (string) config('jwt.secret'), (string) config('jwt.algorithm', 'HS256'));

        $response = $this->call(
            'GET',
            '/api/user',
            [],
            [
                'access_token' => $expiredAccess,
                'refresh_token' => $tokens['refresh_token'],
            ],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $response->assertOk()
            ->assertCookie('access_token')
            ->assertCookie('refresh_token')
            ->assertJsonPath('email', 'silent@example.com');
    }
}
