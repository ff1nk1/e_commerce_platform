<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductCatalog;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\Exceptions\LlmException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeLlmClient;
use Tests\TestCase;

final class SweepMissingEmbeddingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConsoleOutput = false;
    }

    public function test_sweep_embeds_only_products_missing_a_vector(): void
    {
        $llm = new FakeLlmClient;
        $llm->embedHandler = static function (string $text, string $taskType): array {
            if (str_contains($text, 'Broken')) {
                throw LlmException::http('Gemini embed failed (429): quota', 429);
            }

            return array_fill(0, 768, 0.03);
        };
        $this->app->instance(LlmClient::class, $llm);

        $missing = $this->product(1, 'Needs Vector');
        $broken = $this->product(2, 'Broken Shoe');
        $ready = $this->product(3, 'Already Indexed');
        $vector = '['.implode(',', array_fill(0, 768, '0.01')).']';
        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector WHERE id = ?',
            [$vector, $ready->id]
        );

        $exit = Artisan::call('embeddings:sweep-missing');

        $this->assertSame(0, $exit);
        $this->assertSame(2, $llm->embedCalls);
        $this->assertNotNull(DB::table('product_catalog')->where('id', $missing->id)->value('embedding'));
        $this->assertNull(DB::table('product_catalog')->where('id', $broken->id)->value('embedding'));
        $this->assertNotNull(DB::table('product_catalog')->where('id', $ready->id)->value('embedding'));
        $this->assertStringContainsString('Embedded 1 product(s), 1 failed.', Artisan::output());
    }

    public function test_sweep_is_scheduled_hourly(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $event = collect(resolve(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'embeddings:sweep-missing')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }

    private function product(int $productId, string $name): ProductCatalog
    {
        return ProductCatalog::query()->create([
            'product_id' => $productId,
            'store_id' => 1,
            'name' => $name,
            'price' => 10,
            'attributes' => [],
            'is_published' => true,
            'in_stock' => true,
        ]);
    }
}
