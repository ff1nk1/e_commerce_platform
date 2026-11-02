<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Junges\Kafka\Facades\Kafka;
use Tests\TestCase;

final class CategoryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Kafka::fake();
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-User-Id' => '42',
            'X-User-Role' => 'admin',
            'X-User-Name' => 'Yan Admin',
            'X-User-Email' => 'yan@example.com',
        ];
    }

    public function test_store_rejects_parent_that_is_missing(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/categories', [
                'name' => 'Root',
                'slug' => 'root',
                'parent_id' => 999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_store_allows_valid_parent(): void
    {
        $parent = Category::factory()->create();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/admin/categories', [
                'name' => 'Child',
                'slug' => 'child',
                'parent_id' => $parent->id,
            ])
            ->assertCreated();
    }

    public function test_update_rejects_category_as_own_parent(): void
    {
        $category = Category::factory()->create(['name' => 'Self', 'slug' => 'self']);

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/categories/{$category->id}", [
                'parent_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_update_rejects_two_level_cycle(): void
    {
        $parent = Category::factory()->create(['slug' => 'parent']);
        $child = Category::factory()->create([
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        // Re-parent the parent under its own child.
        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/categories/{$parent->id}", [
                'parent_id' => $child->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_update_rejects_three_level_cycle(): void
    {
        $a = Category::factory()->create(['slug' => 'a']);
        $b = Category::factory()->create(['slug' => 'b', 'parent_id' => $a->id]);
        $c = Category::factory()->create(['slug' => 'c', 'parent_id' => $b->id]);

        // A under its own grandchild C would close a loop.
        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/categories/{$a->id}", [
                'parent_id' => $c->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_update_allows_valid_reparent_and_root(): void
    {
        $a = Category::factory()->create(['slug' => 'a']);
        $b = Category::factory()->create(['slug' => 'b']);
        $leaf = Category::factory()->create(['slug' => 'leaf']);

        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/categories/{$leaf->id}", [
                'parent_id' => $a->id,
            ])
            ->assertOk();

        // Existing parent can be moved back to the root.
        $this->withHeaders($this->adminHeaders())
            ->patchJson("/api/admin/categories/{$b->id}", [
                'parent_id' => null,
            ])
            ->assertOk();
    }
}
