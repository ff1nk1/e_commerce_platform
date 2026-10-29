<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\Gemini\GeminiLlmClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GeminiLlmClientTest extends TestCase
{
    private function client(int $timeout = 12): GeminiLlmClient
    {
        return new GeminiLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            chatModel: 'gemini-3.8-flash',
            embeddingModel: 'text-embedding-004',
            temperature: 0.3,
            embeddingDimensions: 768,
            timeout: $timeout,
            embedRetryTimes: 3,
            embedRetrySleepMs: 0,
        );
    }

    public function test_container_resolves_gemini_client(): void
    {
        config([
            'llm.driver' => 'gemini',
            'llm.gemini.api_key' => 'test-key',
        ]);

        $resolved = $this->app->make(LlmClient::class);

        $this->assertInstanceOf(GeminiLlmClient::class, $resolved);
    }

    public function test_embed_returns_float_list(): void
    {
        $values = array_fill(0, 768, 0.1);

        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response([
                'embedding' => ['values' => $values],
            ]),
        ]);

        $embedding = $this->client()->embed('blue running shoes');

        $this->assertCount(768, $embedding);
        $this->assertSame(0.1, $embedding[0]);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'embedContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && ($request['taskType'] ?? null) === 'RETRIEVAL_DOCUMENT'
                && ($request['outputDimensionality'] ?? null) === 768;
        });
    }

    public function test_chat_returns_candidate_text(): void
    {
        Http::fake([
            '*/models/gemini-3.8-flash:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Here are some recommendations.'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $text = $this->client()->chat(
            [ChatMessage::user('Find me shoes')],
            'You are a shopping assistant.'
        );

        $this->assertSame('Here are some recommendations.', $text);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'generateContent')
                && ($body['generationConfig']['temperature'] ?? null) === 0.3
                && ($body['systemInstruction']['parts'][0]['text'] ?? null) === 'You are a shopping assistant.'
                && ($body['contents'][0]['role'] ?? null) === 'user'
                && ($body['contents'][0]['parts'][0]['text'] ?? null) === 'Find me shoes';
        });
    }

    public function test_extract_filters_parses_json_dto(): void
    {
        Http::fake([
            '*/models/gemini-3.8-flash:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                    'store_id' => null,
                                    'store_name' => 'ТЦ Галерея',
                                    'category_name' => 'Джинсы',
                                    'in_stock' => true,
                                    'price_min' => 100,
                                    'price_max' => 500,
                                    'query' => 'джинсы',
                                    'attributes' => [
                                        ['name' => 'Цвет', 'value' => 'чёрный'],
                                    ],
                                ], JSON_THROW_ON_ERROR)],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $filters = $this->client()->extractFilters('черные джинсы в ТЦ Галерея до 500');

        $this->assertNull($filters->storeId);
        $this->assertSame('ТЦ Галерея', $filters->storeName);
        $this->assertSame('Джинсы', $filters->categoryName);
        $this->assertTrue($filters->inStock);
        $this->assertSame(100.0, $filters->priceMin);
        $this->assertSame(500.0, $filters->priceMax);
        $this->assertSame('джинсы', $filters->query);
        $this->assertSame([['name' => 'Цвет', 'value' => 'чёрный']], $filters->attributes);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && isset($body['generationConfig']['responseSchema']['type']);
        });
    }

    public function test_http_error_throws_llm_exception(): void
    {
        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response(['error' => 'boom'], 500),
        ]);

        try {
            $this->client()->embed('fail');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('Gemini embed failed (500)', $e->getMessage());
            $this->assertSame(500, $e->statusCode());
            $this->assertSame(LlmException::KIND_HTTP, $e->kind());
            $this->assertSame(502, $e->publicHttpStatus());
        }
    }

    public function test_extract_filters_503_preserves_status_for_user_message(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 503,
                'message' => 'This model is currently experiencing high demand.',
                'status' => 'UNAVAILABLE',
            ],
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            '*/models/gemini-3.8-flash:generateContent' => Http::response($body, 503),
        ]);

        try {
            $this->client()->extractFilters('найди джинсы');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('Gemini extractFilters failed (503)', $e->getMessage());
            $this->assertStringContainsString('high demand', $e->getMessage());
            $this->assertSame(503, $e->statusCode());
            $this->assertSame(
                'Сервис ИИ сейчас перегружен. Попробуйте чуть позже.',
                $e->userMessage(),
            );
            $this->assertSame(503, $e->publicHttpStatus());
        }
    }

    public function test_missing_api_key_throws_llm_exception(): void
    {
        $client = new GeminiLlmClient(
            apiKey: '',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            chatModel: 'gemini-3.8-flash',
            embeddingModel: 'text-embedding-004',
            temperature: 0.3,
            embeddingDimensions: 768,
            timeout: 12,
        );

        try {
            $client->embed('x');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertSame('GEMINI_API_KEY is not configured', $e->getMessage());
            $this->assertSame(LlmException::KIND_CONFIGURATION, $e->kind());
            $this->assertSame('Сервис рекомендаций временно недоступен.', $e->userMessage());
            $this->assertSame(503, $e->publicHttpStatus());
        }
    }

    public function test_extract_filters_and_embed_embeds_cleaned_query(): void
    {
        $values = array_fill(0, 768, 0.2);

        Http::fake([
            '*/models/gemini-3.8-flash:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                    'store_name' => 'Main',
                                    'query' => 'shoes',
                                    'attributes' => [
                                        ['name' => 'Цвет', 'value' => 'синий'],
                                    ],
                                ], JSON_THROW_ON_ERROR)],
                            ],
                        ],
                    ],
                ],
            ]),
            '*/models/text-embedding-004:embedContent' => Http::response([
                'embedding' => ['values' => $values],
            ]),
        ]);

        [$filters, $embedding] = $this->client()->extractFiltersAndEmbed('blue shoes');

        $this->assertSame('Main', $filters->storeName);
        $this->assertSame('shoes', $filters->query);
        $this->assertCount(768, $embedding);
        $this->assertSame(0.2, $embedding[0]);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'embedContent')) {
                return false;
            }

            return ($request['content']['parts'][0]['text'] ?? null) === 'shoes';
        });
    }

    public function test_extract_filters_uses_zero_temperature(): void
    {
        Http::fake([
            '*/models/gemini-3.8-flash:generateContent' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode(['query' => 'jeans'], JSON_THROW_ON_ERROR)],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->client()->extractFilters('jeans');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ($body['generationConfig']['temperature'] ?? null) === 0
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
    }

    public function test_embed_retries_429_then_returns_vector(): void
    {
        $values = array_fill(0, 768, 0.4);

        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::sequence()
                ->push(['error' => 'rate'], 429)
                ->push(['embedding' => ['values' => $values]], 200),
        ]);

        $embedding = $this->client()->embed('blue running shoes');

        $this->assertCount(768, $embedding);
        $this->assertSame(0.4, $embedding[0]);
        Http::assertSentCount(2);
    }

    public function test_embed_retries_server_errors_then_throws_llm_exception(): void
    {
        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response(['error' => 'boom'], 500),
        ]);

        try {
            $this->client()->embed('fail');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('Gemini embed failed (500)', $e->getMessage());
            $this->assertSame(500, $e->statusCode());
        }

        Http::assertSentCount(3);
    }

    public function test_embed_retries_connection_timeouts(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts): PromiseInterface {
            return $this->embedAttempt($attempts);
        });

        $embedding = $this->client()->embed('shoes');

        $this->assertSame(0.5, $embedding[0]);
        $this->assertSame(3, $attempts);
    }

    public function test_embed_does_not_retry_client_errors(): void
    {
        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response(['error' => 'bad'], 400),
        ]);

        try {
            $this->client()->embed('fail');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('Gemini embed failed (400)', $e->getMessage());
            $this->assertSame(400, $e->statusCode());
        }

        Http::assertSentCount(1);
    }

    public function test_embed_does_not_retry_while_chat_budget_is_active(): void
    {
        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response(['error' => 'boom'], 503),
        ]);

        $client = $this->client();
        $client->startBudget(12);

        try {
            $client->embed('fail');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertSame(503, $e->statusCode());
        } finally {
            $client->clearBudget();
        }

        Http::assertSentCount(1);
    }

    public function test_exhausted_budget_throws_friendly_timeout(): void
    {
        $client = $this->client(timeout: 12);
        $client->startBudget(12);
        // Simulate prior Gemini calls consuming the chat budget.
        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('budgetRemaining');
        $prop->setValue($client, 0.0);

        try {
            $client->embed('x');
            $this->fail('Expected LlmException');
        } catch (LlmException $e) {
            $this->assertSame(504, $e->statusCode());
            $this->assertSame(
                'ИИ слишком долго отвечает. Попробуйте позже.',
                $e->userMessage(),
            );
        } finally {
            $client->clearBudget();
        }
    }

    /**
     * Kept out of the fake closure so PHPStan does not treat the first call's
     * counter as the constant 1 and mark later attempts unreachable.
     */
    private function embedAttempt(int &$attempts): PromiseInterface
    {
        $attempts++;
        throw_if($attempts < 3, ConnectionException::class, 'cURL error 28: Operation timed out');

        return Http::response([
            'embedding' => ['values' => array_fill(0, 768, 0.5)],
        ]);
    }
}
