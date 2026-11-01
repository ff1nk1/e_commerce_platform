<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Auth\LoginCredentials;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\JwtCodec;
use App\Services\Auth\LoginUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Junges\Kafka\Facades\Kafka;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthHttpTest extends TestCase
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

    public function test_registration_returns_201(): void
    {
        Kafka::fake();

        $response = $this->postJson('/api/registration', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'number' => '+12345678901',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'number' => '+12345678901',
        ]);
    }

    public function test_registration_normalizes_phone_with_spaces(): void
    {
        Kafka::fake();

        $response = $this->postJson('/api/registration', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
            'number' => '+48 123 456 789',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'number' => '+48123456789',
        ]);
    }

    #[DataProvider('invalidRegistrationPhoneProvider')]
    public function test_registration_rejects_invalid_phone(string $number): void
    {
        Kafka::fake();

        $response = $this->postJson('/api/registration', [
            'name' => 'John Doe',
            'email' => 'invalid-phone@example.com',
            'password' => 'secret123',
            'number' => $number,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function invalidRegistrationPhoneProvider(): array
    {
        return [
            'missing plus' => ['48123456789'],
            'too short' => ['+481234567'],
            'letters' => ['+48abcdefghi'],
            'leading zero after plus' => ['+048123456789'],
        ];
    }

    public function test_login_sets_http_only_token_cookies(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertCookie('access_token')
            ->assertCookie('refresh_token')
            ->assertJson(['message' => 'Успешно.']);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_endpoint_returns_profile_with_bearer_token(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'me@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'me@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_refresh_issues_new_cookies(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'refresh@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'refresh@example.com',
            'password' => 'password123',
        ]));

        $this->call(
            'POST',
            '/api/refresh',
            [],
            ['refresh_token' => $tokens->refreshToken],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        )->assertOk()
            ->assertCookie('access_token')
            ->assertCookie('refresh_token');
    }

    public function test_logout_revokes_access_token(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'out@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'out@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->withUnencryptedCookie('access_token', $tokens->accessToken)
            ->withUnencryptedCookie('refresh_token', $tokens->refreshToken)
            ->postJson('/api/logout')
            ->assertOk();

        $jwt = resolve(JwtCodec::class);
        $decoded = $jwt->decode($tokens->accessToken);
        $this->assertTrue(
            Cache::has("jwt:blacklist:{$decoded->jti}"),
            'Access token JTI must be blacklisted after logout'
        );

        $request = Request::create('/api/user', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$tokens->accessToken);
        $this->assertNull(
            $jwt->userFromRequest($request),
            'Blacklisted access token must not resolve a user'
        );
    }

    public function test_login_access_cookie_ttl_is_shorter_than_refresh(): void
    {
        config(['jwt.ttl' => 15, 'jwt.refresh_ttl' => 60 * 60 * 24 * 30]);

        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'ttl@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'ttl@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();

        $accessCookie = $response->getCookie('access_token', false);
        $refreshCookie = $response->getCookie('refresh_token', false);

        $this->assertNotNull($accessCookie);
        $this->assertNotNull($refreshCookie);
        $this->assertLessThan(
            $refreshCookie->getExpiresTime(),
            $accessCookie->getExpiresTime(),
            'Access cookie must expire before refresh cookie'
        );
    }

    public function test_change_password_revokes_current_tokens(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'pwd@example.com',
            'password' => Hash::make('old-password'),
            'role_id' => $role->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'pwd@example.com',
            'password' => 'old-password',
        ]));

        $this->withToken($tokens->accessToken)
            ->withUnencryptedCookie('access_token', $tokens->accessToken)
            ->withUnencryptedCookie('refresh_token', $tokens->refreshToken)
            ->patchJson('/api/profile/password', [
                'current_password' => 'old-password',
                'new_password' => 'new-password-123',
            ])
            ->assertOk()
            ->assertCookieExpired('access_token')
            ->assertCookieExpired('refresh_token');

        $jwt = resolve(JwtCodec::class);
        $decoded = $jwt->decode($tokens->accessToken);
        $this->assertTrue(Cache::has("jwt:blacklist:{$decoded->jti}"));

        $refreshHash = hash('sha256', $tokens->refreshToken);
        $this->assertFalse(Redis::exists("refresh_token:{$refreshHash}") > 0);
        $this->assertTrue(Cache::has("refresh_token:blacklist:{$refreshHash}"));

        $request = Request::create('/api/user', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$tokens->accessToken);
        $this->assertNull($jwt->userFromRequest($request));
    }

    public function test_profile_update_normalizes_phone_with_spaces(): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        $user = User::factory()->create([
            'email' => 'profile-phone@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'number' => '+10000000000',
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'profile-phone@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->patchJson('/api/profile', [
                'number' => '+48 123 456 789',
            ])
            ->assertOk()
            ->assertJsonPath('user.number', '+48123456789');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'number' => '+48123456789',
        ]);
    }

    #[DataProvider('invalidRegistrationPhoneProvider')]
    public function test_profile_update_rejects_invalid_phone(string $number): void
    {
        $role = Role::query()->where('slug', 'customer')->first();
        User::factory()->create([
            'email' => 'profile-invalid-phone@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'number' => '+10000000000',
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'profile-invalid-phone@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->patchJson('/api/profile', ['number' => $number])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['number']);
    }
}
