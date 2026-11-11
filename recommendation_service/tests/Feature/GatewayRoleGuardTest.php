<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class GatewayRoleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LlmClient::class, new FakeLlmClient);
    }

    public function test_customer_role_can_use_the_assistant(): void
    {
        $this->withHeaders([
            'X-User-Id' => '7',
            'X-User-Role' => 'customer',
        ])->postJson('/api/recommendations/chat', [
            'message' => 'найди кроссовки',
        ])->assertOk();
    }

    public function test_admin_and_analyst_roles_are_still_allowed(): void
    {
        foreach (['admin', 'analyst'] as $role) {
            $this->withHeaders([
                'X-User-Id' => '9',
                'X-User-Role' => $role,
            ])->postJson('/api/recommendations/chat', [
                'message' => 'найди кроссовки',
            ])->assertOk();
        }
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->withHeaders([
            'X-User-Id' => '7',
            'X-User-Role' => 'ghost',
        ])->postJson('/api/recommendations/chat', [
            'message' => 'найди кроссовки',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Role is not allowed.');
    }

    public function test_missing_role_header_is_still_left_untouched(): void
    {
        $this->withHeader('X-User-Id', '7')
            ->postJson('/api/recommendations/chat', [
                'message' => 'найди кроссовки',
            ])->assertOk();
    }
}
