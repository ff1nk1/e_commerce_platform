<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Auth\LoginCredentials;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtCodec;
use App\Services\Auth\LoginUserService;
use App\Services\Auth\LogoutUserService;
use App\Services\Auth\RefreshTokensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginUserService $loginUser;

    private RefreshTokensService $refreshTokens;

    private LogoutUserService $logoutUser;

    private JwtCodec $jwtService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('jwt.secret', 'real-secret-key-for-integration-tests-12345');
        Config::set('jwt.algorithm', 'HS256');
        Config::set('jwt.ttl', 15);
        Config::set('jwt.refresh_ttl', 100);

        $this->jwtService = resolve(JwtCodec::class);
        $this->loginUser = resolve(LoginUserService::class);
        $this->refreshTokens = resolve(RefreshTokensService::class);
        $this->logoutUser = resolve(LogoutUserService::class);

        Redis::connection()->select((int) config('database.redis.default.database', 15));
        Redis::flushdb();
        Cache::flush();
    }

    public function test_login_stores_refresh_token_hash_in_redis(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        $tokens = $this->loginUser->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'password123',
        ]));

        $hash = hash('sha256', $tokens->refreshToken);
        $this->assertTrue(Redis::exists("refresh_token:{$hash}") > 0);
        $this->assertEquals((string) $user->id, Redis::get("refresh_token:{$hash}"));
    }

    public function test_full_refresh_token_flow_with_redis(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        $tokens = $this->loginUser->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'password123',
        ]));
        $oldRefreshToken = $tokens->refreshToken;
        $oldHash = hash('sha256', $oldRefreshToken);

        $this->assertTrue(Redis::exists("refresh_token:{$oldHash}") > 0);

        $newTokens = $this->refreshTokens->handle($oldRefreshToken);

        $this->assertNotNull($newTokens);
        $this->assertSame(0, (int) Redis::exists("refresh_token:{$oldHash}"));
        $this->assertTrue(Cache::has("refresh_token:blacklist:{$oldHash}"));

        $newHash = hash('sha256', $newTokens->refreshToken);
        $this->assertTrue(Redis::exists("refresh_token:{$newHash}") > 0);
    }

    public function test_cannot_use_blacklisted_refresh_token(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password'),
        ]);

        $tokens = $this->loginUser->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'password',
        ]));
        $refreshToken = $tokens->refreshToken;

        $this->assertNotNull($this->refreshTokens->handle($refreshToken));
        $this->assertNull($this->refreshTokens->handle($refreshToken));
    }

    public function test_logout_invalidates_jwt_access_and_refresh_in_redis(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password'),
        ]);

        $tokens = $this->loginUser->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'password',
        ]));
        $accessToken = $tokens->accessToken;
        $refreshToken = $tokens->refreshToken;
        $refreshHash = hash('sha256', $refreshToken);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$accessToken}");
        $this->assertNotNull($this->jwtService->userFromRequest($request));

        $this->logoutUser->handle($accessToken, $refreshToken);

        $this->assertNull($this->jwtService->userFromRequest($request));

        $decoded = $this->jwtService->decode($accessToken);
        $this->assertTrue(Cache::has("jwt:blacklist:{$decoded->jti}"));
        $this->assertFalse(Redis::exists("refresh_token:{$refreshHash}") > 0);
        $this->assertTrue(Cache::has("refresh_token:blacklist:{$refreshHash}"));
    }
}
