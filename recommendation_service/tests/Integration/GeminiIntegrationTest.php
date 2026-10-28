<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Catalog\DTOs\SearchHit;
use App\Services\Catalog\ProductIndexer;
use App\Services\Catalog\ProductSearchService;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\DTOs\SearchFilters;
use App\Services\Llm\Gemini\GeminiLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Live Gemini smoke tests. Skipped when GEMINI_API_KEY is unset.
 *
 * Run: php artisan test --testsuite=Integration
 */
final class GeminiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ID = 9001;

    private const PRODUCT_NAME = 'алый бархатный диван OrionX';

    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = trim((string) config('llm.gemini.api_key', ''));
        if ($apiKey === '' || $apiKey === 'your_api_key_here') {
            $this->markTestSkipped('GEMINI_API_KEY is not configured');
        }

        // Ensure real Gemini client (no FakeLlmClient).
        $this->app->forgetInstance(LlmClient::class);
        $this->assertInstanceOf(GeminiLlmClient::class, resolve(LlmClient::class));
    }

    public function test_indexer_embeds_product_via_gemini(): void
    {
        $indexer = resolve(ProductIndexer::class);
        $indexer->upsertFromEvent([
            'id' => self::PRODUCT_ID,
            'store_id' => 1,
            'store' => ['id' => 1, 'name' => 'Main'],
            'name' => self::PRODUCT_NAME,
            'description' => 'Мягкий диван для гостиной, бархат, насыщенный алый цвет.',
            'price' => 45990,
            'category' => ['id' => 7, 'name' => 'Мебель'],
            'attributes' => [
                ['name' => 'Цвет', 'value' => 'Алый'],
                ['name' => 'Материал', 'value' => 'Бархат'],
            ],
            'is_published' => true,
            'quantity' => 3,
        ], reembed: true);

        $row = DB::table('product_catalog')->where('product_id', self::PRODUCT_ID)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->embedding);

        $dims = (int) DB::selectOne(
            'SELECT vector_dims(embedding) AS dims FROM product_catalog WHERE product_id = ?',
            [self::PRODUCT_ID]
        )->dims;
        $this->assertSame(768, $dims);
    }

    public function test_vector_search_finds_product_via_gemini(): void
    {
        resolve(ProductIndexer::class)->upsertFromEvent([
            'id' => self::PRODUCT_ID,
            'store_id' => 1,
            'store' => ['id' => 1, 'name' => 'Main'],
            'name' => self::PRODUCT_NAME,
            'description' => 'Мягкий диван для гостиной, бархат, насыщенный алый цвет.',
            'price' => 45990,
            'category' => ['id' => 7, 'name' => 'Мебель'],
            'attributes' => [['name' => 'Цвет', 'value' => 'Алый']],
            'is_published' => true,
            'quantity' => 3,
        ], reembed: true);

        // Distractor with unrelated text so ranking is meaningful.
        resolve(ProductIndexer::class)->upsertFromEvent([
            'id' => self::PRODUCT_ID + 1,
            'store_id' => 1,
            'store' => ['id' => 1, 'name' => 'Main'],
            'name' => 'Игровой ноутбук NeonByte Pro',
            'description' => 'Мощный ноутбук для игр и работы.',
            'price' => 120000,
            'category' => ['id' => 1, 'name' => 'Ноутбуки'],
            'is_published' => true,
            'quantity' => 5,
        ], reembed: true);

        $results = resolve(ProductSearchService::class)->search(
            new SearchFilters(query: 'алый бархатный диван для гостиной OrionX'),
            limit: 5,
        );

        $ids = array_map(static fn (SearchHit $hit): int => (int) $hit->product->product_id, $results);
        $this->assertContains(self::PRODUCT_ID, $ids);
        $this->assertSame(self::PRODUCT_ID, $ids[0] ?? null);
    }

    public function test_chat_end_to_end_via_gemini(): void
    {
        // Live extract+embed+chat needs a generous shared budget (CI/network variance).
        config(['llm.gemini.timeout' => 60]);
        $this->app->forgetInstance(LlmClient::class);

        resolve(ProductIndexer::class)->upsertFromEvent([
            'id' => self::PRODUCT_ID,
            'store_id' => 1,
            'store' => ['id' => 1, 'name' => 'Main'],
            'name' => self::PRODUCT_NAME,
            'description' => 'Мягкий диван для гостиной, бархат, насыщенный алый цвет.',
            'price' => 45990,
            'category' => ['id' => 7, 'name' => 'Мебель'],
            'attributes' => [
                ['name' => 'Цвет', 'value' => 'Алый'],
                ['name' => 'Материал', 'value' => 'Бархат'],
            ],
            'is_published' => true,
            'quantity' => 3,
        ], reembed: true);

        $response = $this->postJson('/api/recommendations/chat', [
            'user_id' => 'integration-user',
            'message' => 'Найди алый бархатный диван OrionX',
            'limit' => 3,
        ]);

        // Soft-skip transient Gemini overload/timeouts; embed/search cover the live key path.
        if (in_array($response->status(), [429, 503, 504], true)) {
            $this->markTestSkipped(
                'Gemini temporarily unavailable ('.$response->status().'): '
                .$response->json('message')
            );
        }

        $response->assertOk();
        $this->assertNotEmpty($response->json('answer'));
        /** @var array<int, array<string, mixed>> $products */
        $products = $response->json('products') ?? [];
        $productIds = collect($products)->pluck('product_id')->all();
        $this->assertContains(self::PRODUCT_ID, $productIds);
        $query = mb_strtolower((string) ($response->json('extracted_filters.query') ?? ''));
        $this->assertTrue($query === '' || str_contains($query, 'диван'));
    }
}
