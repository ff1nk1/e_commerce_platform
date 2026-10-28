<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\TokenBlacklistService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use Tests\TestCase;

final class JwtServiceTest extends TestCase
{
    use RefreshDatabase;

    private JwtService $jwtService;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('jwt.secret', 'test-secret-key-which-should-be-long');
        Config::set('jwt.algorithm', 'HS256');
        Config::set('jwt.ttl', 15);

        $this->jwtService = resolve(JwtService::class);
    }

    public function test_create_access_token_contains_expected_claims(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);

        $token = $this->jwtService->createAccessToken($user);
        $payload = $this->jwtService->decode($token);

        $this->assertEquals($user->id, $payload->sub);
        $this->assertObjectHasProperty('iat', $payload);
        $this->assertObjectHasProperty('exp', $payload);
        $this->assertObjectHasProperty('jti', $payload);
        $this->assertGreaterThan($payload->iat, $payload->exp);
    }

    public function test_decode_rejects_tampered_signature(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);
        $token = $this->jwtService->createAccessToken($user);

        $this->expectException(SignatureInvalidException::class);
        $this->jwtService->decode($token.'tampered');
    }

    public function test_decode_rejects_expired_token(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);

        $now = now()->getTimestamp();
        $token = JWT::encode([
            'sub' => $user->id,
            'iat' => $now - 120,
            'exp' => $now - 60,
            'jti' => bin2hex(random_bytes(8)),
        ], config('jwt.secret'), 'HS256');

        $this->expectException(ExpiredException::class);
        $this->jwtService->decode($token);
    }

    public function test_user_from_request_returns_null_when_no_token_provided(): void
    {
        $request = Request::create('/api/test', 'GET');

        $this->assertNull($this->jwtService->userFromRequest($request));
    }

    public function test_user_from_request_returns_user_with_valid_bearer_token(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);
        $token = $this->jwtService->createAccessToken($user);
        $payload = $this->jwtService->decode($token);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('isJwtBlacklisted')
                ->once()
                ->with($payload->jti)
                ->andReturn(false);
        });

        $result = resolve(JwtService::class)->userFromRequest($request);

        $this->assertNotNull($result);
        $this->assertEquals($user->id, $result->id);
    }

    public function test_user_from_request_reads_access_token_cookie(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);
        $token = $this->jwtService->createAccessToken($user);

        $request = Request::create('/api/test', 'GET');
        $request->cookies->set('access_token', $token);

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isJwtBlacklisted')
                ->once()
                ->andReturn(false);
        });

        $result = resolve(JwtService::class)->userFromRequest($request);

        $this->assertNotNull($result);
        $this->assertEquals($user->id, $result->id);
    }

    public function test_user_from_request_returns_null_when_token_is_blacklisted(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);
        $token = $this->jwtService->createAccessToken($user);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isJwtBlacklisted')->once()->andReturn(true);
        });

        $this->assertNull(resolve(JwtService::class)->userFromRequest($request));
    }

    public function test_revoke_token_writes_jti_to_redis_blacklist(): void
    {
        Role::factory()->create(['id' => 1, 'slug' => 'customer', 'name' => 'Customer']);
        $user = User::factory()->create(['role_id' => 1]);
        $token = $this->jwtService->createAccessToken($user);
        $payload = $this->jwtService->decode($token);

        $this->mock(TokenBlacklistService::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('blacklistJwt')
                ->once()
                ->withArgs(function (string $jti, int $ttl) use ($payload): bool {
                    return $jti === $payload->jti && $ttl > 0;
                });
        });

        resolve(JwtService::class)->revokeToken($token);
    }

    public function test_revoke_token_is_noop_for_invalid_token(): void
    {
        $this->mock(TokenBlacklistService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('blacklistJwt')->never();
        });

        resolve(JwtService::class)->revokeToken('not-a-jwt');
    }
}
