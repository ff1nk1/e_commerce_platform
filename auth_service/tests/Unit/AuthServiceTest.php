<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Auth\LoginCredentials;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtCodec;
use App\Services\Auth\LoginUserService;
use App\Services\Auth\LogoutUserService;
use App\Services\Auth\RefreshTokensService;
use App\Services\Auth\TokenBlacklist;
use App\Support\TokenBlacklistKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.refresh_ttl' => 20160]);
        Cache::setDefaultDriver('array');
    }

    public function test_login_returns_tokens_with_mocked_jwt_service(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('sadd')->once();
        Redis::shouldReceive('expire')->once();

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'password123',
        ]));

        $payload = resolve(JwtCodec::class)->decode($tokens->accessToken);
        $this->assertEquals($user->id, $payload->sub);
        $this->assertNotSame('', $tokens->refreshToken);
    }

    public function test_login_throws_exception_on_invalid_credentials(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('correct_password'),
        ]);

        Redis::shouldReceive('setex')->never();

        $this->expectException(ValidationException::class);

        resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => $user->email,
            'password' => 'wrong_password',
        ]));
    }

    public function test_logout_calls_revoke_on_jwt_service(): void
    {
        $accessToken = 'some.valid.token';

        Redis::shouldReceive('del')->never();

        resolve(LogoutUserService::class)->handle($accessToken, null);
    }

    public function test_logout_blacklists_refresh_token_in_redis(): void
    {
        $accessToken = 'access.token';
        $refreshToken = 'refresh-token-raw-value';
        $hash = hash('sha256', $refreshToken);

        Redis::shouldReceive('get')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn('42');

        Redis::shouldReceive('ttl')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn(3600);

        Redis::shouldReceive('del')
            ->once()
            ->with("refresh_token:{$hash}");

        Redis::shouldReceive('srem')
            ->once()
            ->with('user_refresh_tokens:42', $hash);

        resolve(LogoutUserService::class)->handle($accessToken, $refreshToken);

        $this->assertTrue(Cache::has(TokenBlacklistKeys::refresh($hash)));
    }

    public function test_refresh_tokens_returns_null_for_missing_token(): void
    {
        $this->assertNull(resolve(RefreshTokensService::class)->handle(null));
        $this->assertNull(resolve(RefreshTokensService::class)->handle(''));
    }

    public function test_refresh_tokens_returns_null_when_blacklisted(): void
    {
        $refresh = 'used-refresh';
        $hash = hash('sha256', $refresh);

        resolve(TokenBlacklist::class)->blacklistRefreshToken($hash, 60);

        $this->assertNull(resolve(RefreshTokensService::class)->handle($refresh));
    }

    public function test_refresh_tokens_returns_null_when_unknown(): void
    {
        $refresh = 'unknown-refresh';
        $hash = hash('sha256', $refresh);

        Redis::shouldReceive('get')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn(null);

        $this->assertNull(resolve(RefreshTokensService::class)->handle($refresh));
    }

    public function test_blacklist_refresh_token_uses_fallback_ttl_when_redis_ttl_non_positive(): void
    {
        config(['jwt.refresh_ttl' => 120]);

        $hash = hash('sha256', 'no-ttl-token');

        Cache::shouldReceive('put')
            ->once()
            ->with("refresh_token:blacklist:{$hash}", '1', 120);

        resolve(TokenBlacklist::class)->blacklistRefreshToken($hash, -1);
    }
}
