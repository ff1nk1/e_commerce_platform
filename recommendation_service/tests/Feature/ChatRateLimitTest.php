<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Llm\Contracts\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class ChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(LlmClient::class, new FakeLlmClient);
    }

    public function test_chat_limits_requests_per_user_id(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/chat', [
                'user_id' => 'rate-user',
                'message' => 'найди кроссовки',
            ])->assertOk();
        }

        $this->postJson('/api/recommendations/chat', [
            'user_id' => 'rate-user',
            'message' => 'ещё раз',
        ])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Слишком много запросов. Попробуйте через минуту.');

        $this->postJson('/api/recommendations/chat', [
            'user_id' => 'other-user',
            'message' => 'найди кроссовки',
        ])->assertOk();
    }

    public function test_chat_limit_follows_gateway_user_id(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/chat', [
                'user_id' => 'body-user',
                'message' => 'найди кроссовки',
            ], ['X-User-Id' => 'header-user'])->assertOk();
        }

        $this->postJson('/api/recommendations/chat', [
            'user_id' => 'someone-else',
            'message' => 'ещё раз',
        ], ['X-User-Id' => 'header-user'])->assertTooManyRequests();

        $this->postJson('/api/recommendations/chat', [
            'user_id' => 'body-user',
            'message' => 'найди кроссовки',
        ])->assertOk();
    }

    public function test_chat_limits_requests_without_user_id_by_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/chat', [
                'message' => 'hi',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/recommendations/chat', [
            'message' => 'hi',
        ])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Слишком много запросов. Попробуйте через минуту.');
    }
}
