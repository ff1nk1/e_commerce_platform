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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class CatalogAdminPanelProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->select((int) config('database.redis.default.database', 15));
        Redis::flushdb();
        Cache::flush();

        Role::factory()->create(['name' => 'Admin', 'slug' => 'admin']);
        Role::factory()->create(['name' => 'Customer', 'slug' => 'customer']);
    }

    public function test_admin_catalog_panel_proxy_attaches_identity_headers(): void
    {
        Http::fake([
            '*' => Http::response('<html>catalog-admin</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'Catalog Admin',
            'email' => 'admin-panel@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'admin-panel@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->get('/admin-catalog')->assertOk()->assertSeeHtml('catalog-admin');

        Http::assertSent(function ($request) use ($user): bool {
            return str_contains($request->url(), '/admin-catalog')
                && $request->header('X-User-Id') === [(string) $user->id]
                && $request->header('X-User-Role') === ['admin']
                && $request->header('X-User-Name') === ['Catalog Admin']
                && $request->header('X-User-Email') === ['admin-panel@example.com']
                && $request->hasHeader('Host');
        });
    }

    public function test_admin_catalog_json_proxy_attaches_identity_headers(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200, ['Content-Type' => 'application/json']),
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'Catalog Admin',
            'email' => 'admin-json@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'admin-json@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->get('/api/admin/products?per_page=20')
            ->assertOk();

        Http::assertSent(function ($request) use ($user): bool {
            return str_contains($request->url(), '/api/admin/products')
                && $request->header('X-User-Id') === [(string) $user->id]
                && $request->header('X-User-Role') === ['admin']
                && $request->header('X-User-Name') === ['Catalog Admin']
                && $request->header('X-User-Email') === ['admin-json@example.com'];
        });
    }

    public function test_catalog_admin_panel_requires_authentication(): void
    {
        $this->get('/admin-catalog')->assertUnauthorized();
    }

    public function test_catalog_admin_panel_rejects_non_admin(): void
    {
        $customerRole = Role::query()->where('slug', 'customer')->firstOrFail();
        User::factory()->create([
            'email' => 'customer-panel@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $customerRole->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'customer-panel@example.com',
            'password' => 'password123',
        ]));

        $this->withToken($tokens->accessToken)
            ->get('/admin-catalog')
            ->assertForbidden();
    }

    public function test_admin_catalog_panel_does_not_reencrypt_catalog_session_cookie(): void
    {
        Http::fake([
            '*' => Http::response('<html>catalog-admin</html>', 200, [
                'Content-Type' => 'text/html',
                'Set-Cookie' => 'catalog_service_session=filament-session; path=/admin-catalog; httponly; samesite=lax',
            ]),
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        User::factory()->create([
            'email' => 'admin-session@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'admin-session@example.com',
            'password' => 'password123',
        ]));

        $response = $this->withToken($tokens->accessToken)->get('/admin-catalog');

        $response->assertOk();
        $response->assertPlainCookie('catalog_service_session', 'filament-session');
    }

    public function test_admin_catalog_logout_revokes_tokens_and_clears_cookies(): void
    {
        Http::fake([
            '*/admin-catalog/logout' => Http::response('', 302, [
                'Location' => '/login',
                'Content-Type' => 'text/html; charset=UTF-8',
            ]),
        ]);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        User::factory()->create([
            'email' => 'admin-logout@example.com',
            'password' => Hash::make('password123'),
            'role_id' => $adminRole->id,
        ]);

        $tokens = resolve(LoginUserService::class)->handle(LoginCredentials::fromArray([
            'email' => 'admin-logout@example.com',
            'password' => 'password123',
        ]));

        $response = $this->withToken($tokens->accessToken)
            ->withUnencryptedCookie('refresh_token', $tokens->refreshToken)
            ->post('/admin-catalog/logout');

        $response->assertFound();
        $response->assertCookieExpired('access_token');
        $response->assertCookieExpired('refresh_token');

        $jwt = resolve(JwtCodec::class);
        $decoded = $jwt->decode($tokens->accessToken);
        $this->assertTrue(
            Cache::has("jwt:blacklist:{$decoded->jti}"),
            'Access token JTI must be blacklisted after admin-catalog logout'
        );

        $probe = Request::create('/api/user', 'GET');
        $probe->headers->set('Authorization', 'Bearer '.$tokens->accessToken);
        $this->assertNull(
            $jwt->userFromRequest($probe),
            'Blacklisted access token must not resolve a user'
        );
    }
}
