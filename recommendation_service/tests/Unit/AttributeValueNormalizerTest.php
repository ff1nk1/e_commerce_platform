<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\AttributeValueNormalizer;
use Tests\TestCase;

final class AttributeValueNormalizerTest extends TestCase
{
    public function test_numbers_match_with_inch_marks_from_config(): void
    {
        $n = new AttributeValueNormalizer;

        $this->assertTrue($n->numbersMatch('6.1', '6.1"'));
        $this->assertTrue($n->numbersMatch('6,1 дюйм', '6.1'));
        $this->assertFalse($n->numbersMatch('6.1', '6.7"'));
    }

    public function test_stem_suffixes_come_from_config(): void
    {
        $n = new AttributeValueNormalizer;

        $this->assertSame('син', $n->normalizeForCompare('синего'));
    }
}
