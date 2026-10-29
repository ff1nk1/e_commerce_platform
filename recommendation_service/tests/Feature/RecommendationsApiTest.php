<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductCatalog;
use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;
use App\Services\Llm\Exceptions\LlmException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class RecommendationsApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
    }

    public function test_health_returns_ok(): void
    {
        $this->getJson('/api/recommendations/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', true)
            ->assertJsonPath('pgvector', true);
    }

    public function test_internal_token_rejects_when_configured(): void
    {
        config(['recommendations.internal_token' => 'secret-token']);

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'hello',
        ])->assertUnauthorized();

        $this->withHeader('X-Internal-Token', 'secret-token')
            ->postJson('/api/recommendations/chat', [
                'user_id' => '42',
                'message' => 'hello',
                'limit' => 1,
            ])->assertOk();
    }

    public function test_x_user_id_header_overrides_body_user_id(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $response = $this->withHeader('X-User-Id', 'header-user')
            ->postJson('/api/recommendations/chat', [
                'user_id' => 'body-user',
                'message' => 'sneakers',
                'limit' => 1,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('conversations', [
            'id' => $response->json('conversation_id'),
            'user_id' => 'header-user',
        ]);
    }

    public function test_chat_creates_conversation_and_returns_products(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'Find blue sneakers',
            'limit' => 5,
        ]);

        $response->assertOk()
            ->assertJsonPath('answer', 'Here are some products you might like.')
            ->assertJsonPath('products.0.product_id', 1)
            ->assertJsonPath('products.0.path', '/products/1');

        $conversationId = $response->json('conversation_id');
        $this->assertNotEmpty($conversationId);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'user_id' => '42',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_ASSISTANT,
        ]);

        $this->assertSame(1, $this->llm->extractAndEmbedCalls);
        $this->assertSame(0, $this->llm->extractCalls);
        $this->assertSame(0, $this->llm->embedCalls);
    }

    public function test_chat_still_extracts_when_client_filters_present(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $this->llm->filters = new SearchFilters(
            priceMax: 999,
            query: 'sneakers',
            attributes: [['name' => 'Цвет', 'value' => 'синий']],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'синие sneakers',
            'price_max' => 500,
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(1, $this->llm->extractAndEmbedCalls);
        // Client price_max wins over extracted.
        $this->assertEquals(500, $response->json('extracted_filters.price_max'));
        // Color is preferred (semi-hard); size would be required.
        $this->assertSame([], $response->json('extracted_filters.attributes'));
        $this->assertSame([], $response->json('extracted_filters.required_attributes'));
        $this->assertSame([['name' => 'Цвет', 'value' => 'синий']], $response->json('extracted_filters.preferred_attributes'));
    }

    public function test_chat_marks_size_and_exclusive_color_as_required(): void
    {
        $this->seedProduct(1, 'Blue jeans 28', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '28'],
        ]);
        $this->seedProduct(2, 'Blue jeans 32', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '32'],
        ]);

        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Размер', 'value' => '28'],
            ],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'ищу синие джинсы исключительно 28 размера',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame([], $response->json('extracted_filters.attributes'));
        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ], $response->json('extracted_filters.required_attributes'));
        /** @var array<int, array<string, mixed>> $products */
        $products = $response->json('products') ?? [];
        $this->assertSame([1], collect($products)->pluck('product_id')->all());
    }

    public function test_chat_keeps_products_when_extracted_attributes_do_not_match(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $this->llm->filters = new SearchFilters(
            query: 'sneakers',
            attributes: [['name' => 'Цвет', 'value' => 'красный']],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'красные кроссовки',
            'limit' => 5,
        ]);

        // Preferred color soft-fallback must not empty results when attributes miss.
        $response->assertOk()
            ->assertJsonPath('products.0.product_id', 1);
        $relaxed = $response->json('extracted_filters.relaxed');
        $this->assertNotEmpty($relaxed);
        $this->assertStringContainsString('точный Цвет не обязателен', implode('; ', $relaxed));

        $system = (string) $this->llm->lastChatSystemPrompt;
        $this->assertStringContainsString('запрещено говорить', $system);
        $this->assertStringContainsString('точного совпадения нет', $system);

        $lastUser = collect($this->llm->lastChatMessages)->last();
        $this->assertNotNull($lastUser);
        $this->assertStringContainsString('Ослабленные условия:', $lastUser->content);
        $this->assertStringNotContainsString('Relaxed filters', $lastUser->content);
        $this->assertStringNotContainsString('soft fallback', $lastUser->content);
        $this->assertStringContainsString('Candidates:', $lastUser->content);
        $this->assertStringNotContainsString('Candidates: (none)', $lastUser->content);
    }

    public function test_chat_omits_color_relax_when_final_hits_match_preferred(): void
    {
        $this->seedProduct(201, 'Black jeans 30', attributes: [
            ['name' => 'Цвет', 'value' => 'Черный'],
            ['name' => 'Размер', 'value' => '30'],
        ], categoryName: 'Джинсы');

        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'черные'],
                ['name' => 'Размер', 'value' => '30'],
            ],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'покажи мне черные джинсы 30 размера',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(201, $response->json('products.0.product_id'));
        $relaxed = $response->json('extracted_filters.relaxed') ?? [];
        $this->assertStringNotContainsString('Цвет', implode('; ', $relaxed));

        $lastUser = collect($this->llm->lastChatMessages)->last();
        $this->assertNotNull($lastUser);
        $this->assertStringNotContainsString('точный Цвет не обязателен', $lastUser->content);
    }

    public function test_chat_labels_color_relax_only_when_hits_miss_color(): void
    {
        $this->seedProduct(202, 'Blue jeans 30', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '30'],
        ], categoryName: 'Джинсы');

        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'черные'],
                ['name' => 'Размер', 'value' => '30'],
            ],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'черные джинсы 30 размера',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(202, $response->json('products.0.product_id'));
        $relaxed = $response->json('extracted_filters.relaxed') ?? [];
        $this->assertNotEmpty($relaxed);
        $this->assertStringContainsString('точный Цвет не обязателен', implode('; ', $relaxed));
    }

    public function test_chat_prefers_matching_color_over_mismatch(): void
    {
        $this->seedProduct(1, 'Blue sneakers', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
        ]);
        $this->seedProduct(2, 'Red sneakers', attributes: [
            ['name' => 'Цвет', 'value' => 'Красный'],
        ]);

        $this->llm->filters = new SearchFilters(
            query: 'sneakers',
            attributes: [['name' => 'Цвет', 'value' => 'красный']],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'красные кроссовки',
            'limit' => 5,
        ])->assertOk();

        /** @var array<int, array<string, mixed>> $products */
        $products = $response->json('products') ?? [];
        $this->assertSame([2], collect($products)->pluck('product_id')->all());
        $this->assertSame([['name' => 'Цвет', 'value' => 'красный']], $response->json('extracted_filters.preferred_attributes'));
    }

    public function test_chat_greeting_skips_catalog_search(): void
    {
        $this->seedProduct(1, 'Blue sneakers');
        $this->llm->filters = new SearchFilters;
        $this->llm->lastChatAnswer = 'Привет! Что ищем?';

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'привет',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame('Привет! Что ищем?', $response->json('answer'));
        $this->assertSame([], $response->json('products'));
        $this->assertSame(1, $this->llm->extractAndEmbedCalls);
        $this->assertSame(0, $this->llm->embedCalls);
    }

    public function test_product_search_answer_chat_ignores_prior_turns(): void
    {
        $this->seedProduct(301, 'Phone 6.1', attributes: [
            ['name' => 'Экран', 'value' => '6.1"'],
        ], categoryName: 'Смартфоны');

        $now = now();
        $conversation = Conversation::query()->create([
            'user_id' => '42',
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'active_filters' => [
                'query' => 'джинсы',
                'attributes' => [['name' => 'Цвет', 'value' => 'чёрный']],
            ],
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'черные джинсы 32 размера',
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'По вашему запросу на черные джинсы 32 размера подходящих товаров не нашлось.',
        ]);

        $this->llm->filters = new SearchFilters(
            query: 'телефон',
            attributes: [['name' => 'Экран', 'value' => '6.1']],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'conversation_id' => $conversation->id,
            'message' => 'покажи мне телефон с экраном 6.1',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(301, $response->json('products.0.product_id'));

        $this->assertCount(1, $this->llm->lastChatMessages);
        $this->assertSame('user', $this->llm->lastChatMessages[0]->role);
        $this->assertStringContainsString('телефон с экраном 6.1', $this->llm->lastChatMessages[0]->content);
        $this->assertStringContainsString('Candidates:', $this->llm->lastChatMessages[0]->content);
        $this->assertStringNotContainsString('джинсы', $this->llm->lastChatMessages[0]->content);
        $this->assertStringNotContainsString('не нашлось', $this->llm->lastChatMessages[0]->content);

        $system = (string) $this->llm->lastChatSystemPrompt;
        $this->assertStringContainsString('Не упоминай предыдущие запросы', $system);
    }

    public function test_chat_client_store_id_clears_extracted_store_name(): void
    {
        $this->seedProduct(1, 'Item', attributes: []);
        $this->llm->filters = new SearchFilters(
            storeName: 'Чужой магазин',
            query: 'item',
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'item',
            'store_id' => 1,
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(1, $response->json('extracted_filters.store_id'));
        $this->assertNull($response->json('extracted_filters.store_name'));
    }

    public function test_chat_exclusive_persists_across_turns(): void
    {
        $this->seedProduct(1, 'Blue28', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Размер', 'value' => '28'],
        ]);
        $this->seedProduct(2, 'Black28', attributes: [
            ['name' => 'Цвет', 'value' => 'Чёрный'],
            ['name' => 'Размер', 'value' => '28'],
        ]);

        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Размер', 'value' => '28'],
            ],
        );

        $first = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'только синие джинсы',
            'limit' => 5,
        ])->assertOk();

        $conversationId = $first->json('conversation_id');
        $this->assertSame([], $first->json('extracted_filters.attributes'));
        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ], $first->json('extracted_filters.required_attributes'));

        // Follow-up without exclusive wording — history still hardens color.
        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Размер', 'value' => '28'],
            ],
        );

        $second = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'conversation_id' => $conversationId,
            'message' => 'а размер 28?',
            'limit' => 5,
        ])->assertOk();

        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ], $second->json('extracted_filters.required_attributes'));
        /** @var array<int, array<string, mixed>> $products */
        $products = $second->json('products') ?? [];
        $this->assertSame([1], collect($products)->pluck('product_id')->all());
        $this->assertNotEmpty($this->llm->lastExtractInput);
        $this->assertStringContainsString('Previous structured filters', $this->llm->lastExtractInput);
    }

    public function test_chat_relaxes_impossible_hard_filters(): void
    {
        $this->seedProduct(1, 'Blue jeans', attributes: [
            ['name' => 'Цвет', 'value' => 'Синий'],
            ['name' => 'Материал', 'value' => 'Хлопок'],
        ]);

        $this->llm->filters = new SearchFilters(
            query: 'джинсы',
            attributes: [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Материал', 'value' => 'шёлк'],
            ],
        );

        // Exclusive → both attrs required; silk missing → relax material → keep blue cotton.
        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'только синие шёлковые джинсы',
            'limit' => 5,
        ])->assertOk();

        $this->assertNotEmpty($response->json('extracted_filters.relaxed'));
        /** @var array<int, array<string, mixed>> $products */
        $products = $response->json('products') ?? [];
        $this->assertSame([1], collect($products)->pluck('product_id')->all());
    }

    public function test_chat_logs_when_relevance_gate_drops_all_hits(): void
    {
        $log = Log::spy();
        $this->seedProduct(1, 'Blue sneakers');

        $this->app->instance(RelevanceGate::class, new class implements RelevanceGate
        {
            public function accept(array $hits): array
            {
                return [];
            }
        });

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'sneakers',
            'limit' => 5,
        ])->assertOk()
            ->assertJsonPath('products', []);

        $log->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'RelevanceGate dropped all search hits')
                    && isset($context['hits'], $context['max_cosine_distance']);
            })
            ->once();
    }

    public function test_chat_with_store_id_still_extracts_filters(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'blue sneakers',
            'store_id' => 1,
            'limit' => 5,
        ])->assertOk();

        $this->assertSame(1, $this->llm->extractAndEmbedCalls);
    }

    public function test_get_conversation_returns_persisted_product_cards(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $chat = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'Find blue sneakers',
            'limit' => 5,
        ])->assertOk();

        $conversationId = $chat->json('conversation_id');

        $this->getJson('/api/recommendations/conversations/'.$conversationId.'?user_id=42')
            ->assertOk()
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.products.0.product_id', 1)
            ->assertJsonPath('messages.1.products.0.path', '/products/1');
    }

    public function test_chat_continues_same_conversation_context(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $first = $this->postJson('/api/recommendations/chat', [
            'user_id' => '7',
            'message' => 'Hello',
        ])->assertOk();

        $conversationId = $first->json('conversation_id');

        $second = $this->postJson('/api/recommendations/chat', [
            'user_id' => '7',
            'conversation_id' => $conversationId,
            'message' => 'Something for running',
        ])->assertOk();

        $this->assertSame($conversationId, $second->json('conversation_id'));
        $this->assertSame(4, Message::query()->where('conversation_id', $conversationId)->count());
    }

    public function test_chat_rejects_foreign_conversation(): void
    {
        $now = now();
        $conversation = Conversation::query()->create([
            'user_id' => '1',
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ]);

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '2',
            'conversation_id' => $conversation->id,
            'message' => 'Hi',
        ])->assertNotFound();
    }

    public function test_chat_rejects_expired_conversation(): void
    {
        $conversation = Conversation::query()->create([
            'user_id' => '42',
            'last_activity_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'conversation_id' => $conversation->id,
            'message' => 'Hi again',
            'limit' => 1,
        ])->assertNotFound();

        // Still in DB during grace — cleanup must not have removed it yet.
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_chat_injects_phone_scoped_attribute_names_and_resolves_diagonal(): void
    {
        $this->seedProduct(101, 'Phone A', attributes: [
            ['name' => 'Экран', 'value' => '6.1"'],
            ['name' => 'Память', 'value' => '128 ГБ'],
        ], categoryName: 'Смартфоны');
        $this->seedProduct(102, 'Jeans B', attributes: [
            ['name' => 'Размер', 'value' => '32'],
            ['name' => 'Цвет', 'value' => 'Синий'],
        ], categoryName: 'Джинсы');

        $this->llm->filters = new SearchFilters(
            query: 'телефон',
            attributes: [['name' => 'Диагональ', 'value' => '6.1']],
        );

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'найди телефон с диагональю 6.1',
            'limit' => 5,
        ])->assertOk();

        $this->assertContains('Экран', $this->llm->lastCatalogAttributeNames);
        $this->assertNotContains('Размер', $this->llm->lastCatalogAttributeNames);
        // Диагональ → Экран via synonym group against phone-scoped vocab (soft bucket).
        $this->assertSame(
            [['name' => 'Экран', 'value' => '6.1']],
            $response->json('extracted_filters.attributes')
        );
        $this->assertSame(101, $response->json('products.0.product_id'));
    }

    public function test_chat_without_product_type_hint_skips_global_attr_dump(): void
    {
        $this->seedProduct(1, 'Blue sneakers');

        $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'что-нибудь подешевле',
            'limit' => 1,
        ])->assertOk();

        $this->assertSame([], $this->llm->lastCatalogAttributeNames);
    }

    public function test_get_and_delete_conversation_are_scoped_to_owner(): void
    {
        $now = now();
        $conversation = Conversation::query()->create([
            'user_id' => '9',
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'Hi',
        ]);

        $this->getJson('/api/recommendations/conversations/'.$conversation->id.'?user_id=9')
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hi');

        $this->getJson('/api/recommendations/conversations/'.$conversation->id.'?user_id=8')
            ->assertNotFound();

        $this->deleteJson('/api/recommendations/conversations/'.$conversation->id, [
            'user_id' => '9',
        ])->assertNoContent();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    }

    public function test_reindex_updates_local_embeddings_without_external_http(): void
    {
        $product = ProductCatalog::query()->create([
            'product_id' => 11,
            'store_id' => 1,
            'name' => 'Jacket',
            'description' => 'Warm',
            'category_name' => 'Clothes',
            'price' => 50,
            'attributes' => [],
            'is_published' => true,
            'in_stock' => true,
        ]);

        $this->postJson('/api/recommendations/reindex')
            ->assertOk()
            ->assertJsonPath('indexed', 1);

        $embedding = DB::table('product_catalog')->where('id', $product->id)->value('embedding');
        $this->assertNotNull($embedding);
    }

    public function test_reindex_on_empty_table_returns_zero(): void
    {
        $this->assertSame(0, ProductCatalog::query()->count());

        $this->postJson('/api/recommendations/reindex')
            ->assertOk()
            ->assertJsonPath('indexed', 0);
    }

    public function test_reindex_continues_when_one_embed_fails(): void
    {
        ProductCatalog::query()->create([
            'product_id' => 21,
            'store_id' => 1,
            'name' => 'Fail Jacket',
            'price' => 10,
            'attributes' => [],
            'is_published' => true,
            'in_stock' => true,
        ]);
        ProductCatalog::query()->create([
            'product_id' => 22,
            'store_id' => 1,
            'name' => 'Ok Jacket',
            'price' => 20,
            'attributes' => [],
            'is_published' => true,
            'in_stock' => true,
        ]);

        $this->llm->embedHandler = static function (string $text, string $taskType): array {
            if (str_contains($text, 'Fail Jacket')) {
                throw LlmException::http('Gemini embed failed (503): down', 503);
            }

            return array_fill(0, 768, $taskType === 'RETRIEVAL_DOCUMENT' ? 0.02 : 0.01);
        };

        $this->postJson('/api/recommendations/reindex')
            ->assertOk()
            ->assertJsonPath('indexed', 1);

        $this->assertNull(DB::table('product_catalog')->where('product_id', 21)->value('embedding'));
        $this->assertNotNull(DB::table('product_catalog')->where('product_id', 22)->value('embedding'));
    }

    public function test_chat_returns_friendly_message_when_gemini_unavailable(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
            {
                return [];
            }

            public function chat(array $messages, ?string $systemPrompt = null): string
            {
                return '';
            }

            public function extractFilters(string $userMessage, array $catalogAttributeNames = []): SearchFilters
            {
                throw LlmException::http(
                    'Gemini extractFilters failed (503): {"error":{"code":503,"message":"This model is currently experiencing high demand.","status":"UNAVAILABLE"}}',
                    503,
                );
            }

            public function extractFiltersAndEmbed(
                string $userMessage,
                string $embedTaskType = 'RETRIEVAL_QUERY',
                ?string $embedText = null,
                array $catalogAttributeNames = [],
            ): array {
                return [$this->extractFilters($userMessage, $catalogAttributeNames), []];
            }
        });

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => '42',
            'message' => 'найди джинсы',
            'limit' => 5,
        ]);

        $response->assertServiceUnavailable()
            ->assertJsonPath('message', 'Сервис ИИ сейчас перегружен. Попробуйте чуть позже.')
            ->assertJsonMissingPath('exception');

        $this->assertStringNotContainsString('high demand', $response->getContent());
        $this->assertStringNotContainsString('Gemini extractFilters', $response->getContent());
    }

    /**
     * @param  list<array{name: string, value: string}>|null  $attributes
     */
    private function seedProduct(
        int $productId,
        string $name,
        ?array $attributes = null,
        string $categoryName = 'Shoes',
    ): void {
        $product = ProductCatalog::query()->create([
            'product_id' => $productId,
            'store_id' => 1,
            'store_name' => 'Main Store',
            'name' => $name,
            'description' => $name,
            'category_id' => 2,
            'category_name' => $categoryName,
            'image_url' => '/img/'.$productId.'.jpg',
            'price' => 100,
            'attributes' => $attributes ?? [['name' => 'Color', 'value' => 'Blue']],
            'is_published' => true,
            'in_stock' => true,
        ]);

        $vector = '['.implode(',', array_fill(0, 768, '0.01')).']';
        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector WHERE id = ?',
            [$vector, $product->id]
        );
    }
}
