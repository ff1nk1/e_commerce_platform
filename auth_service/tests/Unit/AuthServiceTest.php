<?php

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\JwtService;
use App\Services\Auth\TokenBlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.refresh_ttl' => 20160]);
    }

    public function test_login_returns_tokens_with_mocked_jwt_service(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('password123'),
        ]);

        $fakeJwt = 'fake.access.token';

        $this->mock(JwtService::class, function (MockInterface $mock) use ($user, $fakeJwt) {
            $mock->shouldReceive('createAccessToken')
                ->once()
                ->withArgs(fn ($arg) => $arg->id === $user->id)
                ->andReturn($fakeJwt);
        });

        Redis::shouldReceive('setex')->once();
        Redis::shouldReceive('sadd')->once();
        Redis::shouldReceive('expire')->once();

        $tokens = app(AuthService::class)->login([
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $this->assertEquals($fakeJwt, $tokens['access_token']);
        $this->assertArrayHasKey('refresh_token', $tokens);
    }

    public function test_login_throws_exception_on_invalid_credentials(): void
    {
        $role = Role::factory()->create(['slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password' => Hash::make('correct_password'),
        ]);

        $this->mock(JwtService::class, function (MockInterface $mock) {
            $mock->shouldReceive('createAccessToken')->never();
        });

        Redis::shouldReceive('setex')->never();

        $this->expectException(ValidationException::class);

        app(AuthService::class)->login([
            'email' => $user->email,
            'password' => 'wrong_password',
        ]);
    }

    public function test_logout_calls_revoke_on_jwt_service(): void
    {
        $accessToken = 'some.valid.token';

        $this->mock(JwtService::class, function (MockInterface $mock) use ($accessToken) {
            $mock->shouldReceive('revokeToken')->once()->with($accessToken);
        });

        Redis::shouldReceive('del')->never();

        app(AuthService::class)->logout($accessToken, null);
    }

    public function test_logout_blacklists_refresh_token_in_redis(): void
    {
        $accessToken = 'access.token';
        $refreshToken = 'refresh-token-raw-value';
        $hash = hash('sha256', $refreshToken);

        $this->mock(JwtService::class, function (MockInterface $mock) use ($accessToken) {
            $mock->shouldReceive('revokeToken')->once()->with($accessToken);
        });

        Redis::shouldReceive('get')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn('42');

        Redis::shouldReceive('ttl')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn(3600);

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock) use ($hash) {
            $mock->shouldReceive('blacklistRefreshToken')
                ->once()
                ->with($hash, 3600);
        });

        Redis::shouldReceive('del')
            ->once()
            ->with("refresh_token:{$hash}");

        Redis::shouldReceive('srem')
            ->once()
            ->with('user_refresh_tokens:42', $hash);

        app(AuthService::class)->logout($accessToken, $refreshToken);
    }

    public function test_refresh_tokens_returns_null_for_missing_token(): void
    {
        $this->assertNull(app(AuthService::class)->refreshTokens(null));
        $this->assertNull(app(AuthService::class)->refreshTokens(''));
    }

    public function test_refresh_tokens_returns_null_when_blacklisted(): void
    {
        $refresh = 'used-refresh';
        $hash = hash('sha256', $refresh);

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock) use ($hash) {
            $mock->shouldReceive('isRefreshTokenBlacklisted')
                ->once()
                ->with($hash)
                ->andReturn(true);
        });

        $this->assertNull(app(AuthService::class)->refreshTokens($refresh));
    }

    public function test_refresh_tokens_returns_null_when_unknown(): void
    {
        $refresh = 'unknown-refresh';
        $hash = hash('sha256', $refresh);

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock) use ($hash) {
            $mock->shouldReceive('isRefreshTokenBlacklisted')
                ->once()
                ->with($hash)
                ->andReturn(false);
        });

        Redis::shouldReceive('get')
            ->once()
            ->with("refresh_token:{$hash}")
            ->andReturn(null);

        $this->assertNull(app(AuthService::class)->refreshTokens($refresh));
    }

    public function test_blacklist_refresh_token_uses_fallback_ttl_when_redis_ttl_non_positive(): void
    {
        config(['jwt.refresh_ttl' => 120]);

        $hash = hash('sha256', 'no-ttl-token');

        Cache::shouldReceive('put')
            ->once()
            ->with("refresh_token:blacklist:{$hash}", '1', 120);

        app(TokenBlacklistService::class)->blacklistRefreshToken($hash, -1);
    }
}
