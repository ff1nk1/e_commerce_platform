<?php

namespace Tests\Unit;

use App\Services\Catalog\AttributeResolver;
use Tests\TestCase;

class AttributeResolverTest extends TestCase
{
    public function test_resolves_diagonal_to_screen_in_phone_vocab(): void
    {
        $resolver = new AttributeResolver;
        $vocab = ['Экран', 'Память', 'ОЗУ', 'Камера', 'Аккумулятор', 'ОС'];

        $resolved = $resolver->resolveOne('Диагональ', '6.1', $vocab);

        $this->assertNotNull($resolved);
        $this->assertSame('Экран', $resolved['name']);
        $this->assertSame('6.1', $resolved['value']);
        $this->assertArrayNotHasKey('value_only', $resolved);
    }

    public function test_unresolved_in_scoped_vocab_is_value_only(): void
    {
        $resolver = new AttributeResolver;
        $resolved = $resolver->resolveOne('НесуществующееПоле', '6.1', ['Экран', 'Память']);

        $this->assertTrue($resolved['value_only'] ?? false);
    }

    public function test_empty_vocab_keeps_canonical_name(): void
    {
        $resolver = new AttributeResolver;
        $resolved = $resolver->resolveOne('color', 'синий', []);

        $this->assertSame('Цвет', $resolved['name']);
        $this->assertArrayNotHasKey('value_only', $resolved);
    }
}
