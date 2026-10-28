<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\AttributeConstraintDetector;
use Tests\TestCase;

final class AttributeConstraintDetectorTest extends TestCase
{
    private AttributeConstraintDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AttributeConstraintDetector;
    }

    public function test_size_is_always_required_and_color_is_preferred(): void
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
        ], $split['preferred']);
        $this->assertSame([], $split['soft']);
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
        $this->assertSame([], $split['preferred']);
        $this->assertSame([], $split['soft']);
    }

    public function test_exclusive_from_history_hardens_follow_up(): void
    {
        $split = $this->detector->split(
            'а размер 28?',
            [
                ['name' => 'Цвет', 'value' => 'синий'],
                ['name' => 'Размер', 'value' => '28'],
            ],
            exclusiveFromHistory: true,
        );

        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'синий'],
            ['name' => 'Размер', 'value' => '28'],
        ], $split['required']);
    }

    public function test_history_implies_exclusive_scans_user_turns(): void
    {
        $this->assertTrue($this->detector->historyImpliesExclusive([
            (object) ['role' => 'user', 'content' => 'только синие джинсы'],
            (object) ['role' => 'assistant', 'content' => 'Вот варианты'],
        ]));
        $this->assertFalse($this->detector->historyImpliesExclusive([
            (object) ['role' => 'user', 'content' => 'синие джинсы'],
        ]));
    }

    public function test_detects_exclusive_keywords(): void
    {
        $this->assertTrue($this->detector->messageImpliesExclusive('только чёрные'));
        $this->assertTrue($this->detector->messageImpliesExclusive('именно 30 размер'));
        $this->assertFalse($this->detector->messageImpliesExclusive('синие джинсы 28 размера'));
    }

    public function test_normalizes_color_alias_to_canonical(): void
    {
        $split = $this->detector->split('black shoes', [
            ['name' => 'color', 'value' => 'black'],
        ]);

        $this->assertSame([
            ['name' => 'Цвет', 'value' => 'black'],
        ], $split['preferred']);
    }
}
