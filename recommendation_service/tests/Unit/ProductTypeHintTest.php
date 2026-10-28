<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\ProductTypeHint;
use Tests\TestCase;

final class ProductTypeHintTest extends TestCase
{
    public function test_detects_phone_keywords(): void
    {
        $hint = (new ProductTypeHint)->resolve('найди телефон с диагональю 6.1');

        $this->assertNotNull($hint);
        $this->assertSame('smartphones', $hint['key']);
        $this->assertSame('Смартфон', $hint['category_ilike']);
    }

    public function test_message_keyword_overrides_sticky_jeans(): void
    {
        $hint = (new ProductTypeHint)->resolve('а теперь ноутбук', [
            'query' => 'джинсы',
            'category_name' => 'Джинсы',
        ]);

        $this->assertNotNull($hint);
        $this->assertSame('laptops', $hint['key']);
    }

    public function test_uses_sticky_query_when_message_has_no_type(): void
    {
        $hint = (new ProductTypeHint)->resolve('синие', [
            'query' => 'джинсы',
        ]);

        $this->assertNotNull($hint);
        $this->assertSame('jeans', $hint['key']);
    }

    public function test_returns_null_without_hint(): void
    {
        $this->assertNull((new ProductTypeHint)->resolve('что-нибудь подешевле'));
    }
}
