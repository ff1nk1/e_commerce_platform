<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCatalogLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_request_guard_supports_logout(): void
    {
        $this->withHeaders([
            'X-User-Id' => '42',
            'X-User-Role' => 'admin',
            'X-User-Name' => 'Yan Admin',
            'X-User-Email' => 'yan@example.com',
        ]);

        $response = $this->get('/admin-catalog');
        $this->assertTrue(in_array($response->status(), [200, 302], true));

        $guard = Filament::auth();
        $this->assertNotNull($guard->user());

        // RequestGuard has no logout() by default — macro must make this a no-throw.
        $guard->logout();
        $this->assertNull($guard->user());
    }

    public function test_filament_logout_redirects_to_login_and_clears_jwt_cookies(): void
    {
        // Skip CSRF: this test targets logout redirect + JWT cookie clearing, not the
        // browser session handshake that normally supplies the token after loading the panel.
        $response = $this->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders([
                'X-User-Id' => '42',
                'X-User-Role' => 'admin',
                'X-User-Name' => 'Yan Admin',
                'X-User-Email' => 'yan@example.com',
            ])->post('/admin-catalog/logout');

        $response->assertRedirect('/login');
        $response->assertCookieExpired('access_token');
        $response->assertCookieExpired('refresh_token');
    }
}
