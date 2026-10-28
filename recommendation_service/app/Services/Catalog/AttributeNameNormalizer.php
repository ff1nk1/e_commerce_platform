<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\RuText;

/**
 * Maps LLM / user attribute names to catalog-canonical labels.
 */
final class AttributeNameNormalizer
{
    /** @var array<string, string> lowercase alias → canonical */
    private const ALIASES = [
        'цвет' => 'Цвет',
        'color' => 'Цвет',
        'colour' => 'Цвет',
        'размер' => 'Размер',
        'size' => 'Размер',
        'бренд' => 'Бренд',
        'brand' => 'Бренд',
        'марка' => 'Бренд',
        'материал' => 'Материал',
        'material' => 'Материал',
    ];

    public function canonicalize(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        $key = $this->normalizeKey($trimmed);

        return self::ALIASES[$key] ?? $trimmed;
    }

    public function namesMatch(string $left, string $right): bool
    {
        $a = $this->canonicalize($left);
        $b = $this->canonicalize($right);
        if ($a === '' || $b === '') {
            return false;
        }

        return $this->normalizeKey($a) === $this->normalizeKey($b);
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @return list<array{name: string, value: string}>
     */
    public function normalizeList(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $row) {
            $name = $this->canonicalize((string) $row['name']);
            $value = trim((string) $row['value']);
            if ($name === '' || $value === '') {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    private function normalizeKey(string $value): string
    {
        return RuText::fold($value);
    }
}
