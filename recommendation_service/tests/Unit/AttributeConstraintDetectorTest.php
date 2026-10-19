<?php

namespace Tests\Unit;

use App\Services\Catalog\AttributeConstraintDetector;
use Tests\TestCase;

class AttributeConstraintDetectorTest extends TestCase
{
    private AttributeConstraintDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AttributeConstraintDetector;
    }

    public function test_size_is_always_required(): void
    {
        $split = $this->detector->split('синие джинсы 28 размера', [
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ]);

        $this->assertSame([
            ['name' => 'Размер', 'value' => '28'],
        ], $split['required']);
        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
        ], $split['soft']);
    }

    public function test_exclusive_language_makes_all_attributes_required(): void
    {
        $split = $this->detector->split('ищу синие джинсы исключительно 28 размера', [
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ]);

        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ], $split['required']);
        $this->assertSame([], $split['soft']);
    }

    public function test_detects_exclusive_keywords(): void
    {
        $this->assertTrue($this->detector->messageImpliesExclusive('только чёрные'));
        $this->assertTrue($this->detector->messageImpliesExclusive('именно 30 размер'));
        $this->assertFalse($this->detector->messageImpliesExclusive('синие джинсы 28 размера'));
    }
}
