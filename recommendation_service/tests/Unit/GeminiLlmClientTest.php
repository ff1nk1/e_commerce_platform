<?php

namespace Tests\Unit;

use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\ChatMessage;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\Gemini\GeminiLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiLlmClientTest extends TestCase
{
    private function client(): GeminiLlmClient
    {
        return new GeminiLlmClient(
            apiKey: 'test-key',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta',
            chatModel: 'gemini-3.8-flash',
            embeddingModel: 'text-embedding-004',
            temperature: 0.3,
            embeddingDimensions: 768,
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

        Http::assertSent(function ($request) {
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

        Http::assertSent(function ($request) {
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

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
    }

    public function test_http_error_throws_llm_exception(): void
    {
        Http::fake([
            '*/models/text-embedding-004:embedContent' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Gemini embed failed (500)');

        $this->client()->embed('fail');
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
        );

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY is not configured');

        $client->embed('x');
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
        Http::assertSent(function ($request) {
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

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['generationConfig']['temperature'] ?? null) === 0
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
    }
}
