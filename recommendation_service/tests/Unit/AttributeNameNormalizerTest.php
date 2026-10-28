<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\AttributeNameNormalizer;
use PHPUnit\Framework\TestCase;

final class AttributeNameNormalizerTest extends TestCase
{
    public function test_aliases_map_to_canonical(): void
    {
        $n = new AttributeNameNormalizer;

        $this->assertSame('Цвет', $n->canonicalize('color'));
        $this->assertSame('Цвет', $n->canonicalize('цвет'));
        $this->assertSame('Бренд', $n->canonicalize('brand'));
        $this->assertSame('Размер', $n->canonicalize('size'));
        $this->assertSame('Материал', $n->canonicalize('material'));
    }

    public function test_names_match_via_aliases_not_substring(): void
    {
        $n = new AttributeNameNormalizer;

        $this->assertTrue($n->namesMatch('Цвет', 'color'));
        $this->assertFalse($n->namesMatch('Цвет', 'Цвет подошвы'));
        $this->assertFalse($n->namesMatch('Цвет', 'цвет подошвы'));
    }
}
