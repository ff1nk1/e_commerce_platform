<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\AttributeValueMatcher;
use App\Services\Catalog\ValueMatchStrategyResolver;
use Tests\TestCase;

final class AttributeValueMatcherTest extends TestCase
{
    public function test_numeric_matches_inch_variants_via_config_strip(): void
    {
        $matcher = new AttributeValueMatcher;

        $this->assertTrue($matcher->matches('Экран', '6.1"', '6.1'));
        $this->assertTrue($matcher->matches('Диагональ', '6.1', '6,1 дюйм'));
        $this->assertFalse($matcher->matches('Экран', '6.7"', '6.1'));
    }

    public function test_text_stem_matches_color_forms(): void
    {
        $matcher = new AttributeValueMatcher;

        $this->assertTrue($matcher->matches('Цвет', 'синий', 'синего'));
        $this->assertTrue($matcher->matches('Цвет', 'Синий', 'синий'));
    }

    public function test_size_range_from_and_exact(): void
    {
        $matcher = new AttributeValueMatcher;

        $this->assertTrue($matcher->matches('Размер', '32', 'от 30'));
        $this->assertFalse($matcher->matches('Размер', '28', 'от 30'));
        $this->assertTrue($matcher->matches('Размер', '28', '28'));
        $this->assertFalse($matcher->matches('Размер', '32', '28'));
    }

    public function test_strategy_from_config_is_overridable(): void
    {
        // Comma vs dot: numeric equates, text substring does not.
        $numericMatcher = new AttributeValueMatcher;
        $this->assertTrue($numericMatcher->matches('Экран', '6.1', '6,1'));

        config([
            'attributes.value_match.strategies_by_concept.screen' => ValueMatchStrategyResolver::STRATEGY_TEXT,
        ]);

        $textMatcher = new AttributeValueMatcher;
        $this->assertFalse($textMatcher->matches('Экран', '6.1', '6,1'));
        $this->assertSame(ValueMatchStrategyResolver::STRATEGY_TEXT, $textMatcher->strategyFor('Экран'));
    }

    public function test_resolver_maps_size_aliases_to_size_range(): void
    {
        $resolver = new ValueMatchStrategyResolver;

        $this->assertTrue($resolver->isSizeRange('Размер'));
        $this->assertTrue($resolver->isSizeRange('size'));
        $this->assertFalse($resolver->isSizeRange('Цвет'));
        $this->assertSame(ValueMatchStrategyResolver::STRATEGY_NUMERIC, $resolver->strategyFor('Экран'));
    }
}
