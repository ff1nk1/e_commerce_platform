<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SchemaAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgvector_extension_is_available(): void
    {
        $exists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') AS present"
        );

        $this->assertTrue((bool) ($exists->present ?? false));
    }

    public function test_product_catalog_stores_json_attributes_and_nullable_embedding(): void
    {
        $row = ProductCatalog::query()->create([
            'product_id' => 42,
            'store_id' => 7,
            'attributes' => [
                ['name' => 'Color', 'value' => 'Blue'],
            ],
            'is_published' => true,
        ]);

        $this->assertDatabaseHas('product_catalog', [
            'id' => $row->id,
            'product_id' => 42,
            'store_id' => 7,
            'is_published' => true,
        ]);

        $this->assertNull(
            DB::table('product_catalog')->where('id', $row->id)->value('embedding')
        );

        $vector = '['.implode(',', array_fill(0, 768, '0')).']';
        DB::statement(
            'UPDATE product_catalog SET embedding = ?::vector WHERE id = ?',
            [$vector, $row->id]
        );

        $stored = DB::selectOne(
            'SELECT embedding::text AS embedding FROM product_catalog WHERE id = ?',
            [$row->id]
        );

        $this->assertNotEmpty($stored->embedding ?? null);
        $this->assertSame(
            [['name' => 'Color', 'value' => 'Blue']],
            $row->fresh()->attributes
        );
    }

    public function test_deleting_conversation_cascades_to_messages(): void
    {
        $now = now();
        $conversation = Conversation::query()->create([
            'user_id' => 'user-1',
            'last_activity_at' => $now,
            'expires_at' => Conversation::computeExpiresAt($now, $now),
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => Message::ROLE_USER,
            'content' => 'Hello',
        ]);

        $this->assertDatabaseHas('messages', ['id' => $message->id]);

        $conversation->delete();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_cleanup_command_deletes_expired_conversations_and_messages(): void
    {
        // Past grace: expired more than CLEANUP_GRACE_MINUTES ago.
        $pastGraceAt = now()->subMinutes(Conversation::CLEANUP_GRACE_MINUTES + 1);
        $activeAt = now()->addHour();

        $expired = Conversation::query()->create([
            'user_id' => 'user-expired',
            'last_activity_at' => now()->subHours(2),
            'expires_at' => $pastGraceAt,
        ]);
        Message::query()->create([
            'conversation_id' => $expired->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'Old reply',
        ]);

        $active = Conversation::query()->create([
            'user_id' => 'user-active',
            'last_activity_at' => now(),
            'expires_at' => $activeAt,
        ]);
        Message::query()->create([
            'conversation_id' => $active->id,
            'role' => Message::ROLE_USER,
            'content' => 'Still chatting',
        ]);

        $exit = Artisan::call('conversations:cleanup');

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('conversations', ['id' => $expired->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $expired->id]);
        $this->assertDatabaseHas('conversations', ['id' => $active->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $active->id]);
    }

    public function test_cleanup_keeps_conversations_inside_grace_period(): void
    {
        $insideGrace = Conversation::query()->create([
            'user_id' => 'user-grace',
            'last_activity_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);
        Message::query()->create([
            'conversation_id' => $insideGrace->id,
            'role' => Message::ROLE_USER,
            'content' => 'Just expired for API, still in grace',
        ]);

        Artisan::call('conversations:cleanup');

        $this->assertDatabaseHas('conversations', ['id' => $insideGrace->id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $insideGrace->id]);
    }

    public function test_cleanup_deletes_exactly_at_grace_boundary(): void
    {
        $atBoundary = Conversation::query()->create([
            'user_id' => 'user-boundary',
            'last_activity_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(Conversation::CLEANUP_GRACE_MINUTES),
        ]);

        Artisan::call('conversations:cleanup');

        $this->assertDatabaseMissing('conversations', ['id' => $atBoundary->id]);
    }
}
