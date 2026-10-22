<?php

namespace App\Services\Catalog;

use App\Support\RuText;

/**
 * Decides which extracted attributes are hard, preferred (semi-hard), or soft.
 */
class AttributeConstraintDetector
{
    public function __construct(
        private readonly AttributeNameNormalizer $names = new AttributeNameNormalizer,
        private readonly ValueMatchStrategyResolver $valueStrategies = new ValueMatchStrategyResolver,
    ) {}

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @return array{
     *     required: list<array{name: string, value: string}>,
     *     preferred: list<array{name: string, value: string}>,
     *     soft: list<array{name: string, value: string}>
     * }
     */
    public function split(string $userMessage, array $attributes, bool $exclusiveFromHistory = false): array
    {
        $exclusive = $exclusiveFromHistory || $this->messageImpliesExclusive($userMessage);
        $required = [];
        $preferred = [];
        $soft = [];

        foreach ($attributes as $attr) {
            $name = trim($attr['name']);
            $value = trim($attr['value']);
            if ($name === '' || $value === '') {
                continue;
            }
            $canonical = $this->names->canonicalize($name);
            $row = ['name' => $canonical !== '' ? $canonical : $name, 'value' => $value];

            if ($exclusive || $this->isSizeAttributeName($row['name'])) {
                $required[] = $row;
            } elseif ($this->isPreferredAttributeName($row['name'])) {
                $preferred[] = $row;
            } else {
                $soft[] = $row;
            }
        }

        return [
            'required' => $required,
            'preferred' => $preferred,
            'soft' => $soft,
        ];
    }

    public function messageImpliesExclusive(string $message): bool
    {
        $normalized = RuText::fold($message);

        return preg_match(
            '/(^|[^\p{L}\p{N}])(только|исключительно|именно|строго|ровно|точно)($|[^\p{L}\p{N}])/u',
            $normalized
        ) === 1;
    }

    /**
     * @param  iterable<int, object{role?: string, content?: string}|array{role?: string, content?: string}>  $history
     */
    public function historyImpliesExclusive(iterable $history): bool
    {
        foreach ($history as $row) {
            $role = is_array($row) ? ($row['role'] ?? '') : ($row->role ?? '');
            $content = is_array($row) ? (string) ($row['content'] ?? '') : (string) ($row->content ?? '');
            if ($role === 'user' && $this->messageImpliesExclusive($content)) {
                return true;
            }
        }

        return false;
    }

    public function isSizeAttributeName(string $name): bool
    {
        return $this->valueStrategies->isSizeRange($name);
    }

    public function isPreferredAttributeName(string $name): bool
    {
        $canonical = $this->names->canonicalize($name);
        $key = RuText::fold($canonical);

        return in_array($key, ['цвет', 'бренд', 'материал'], true);
    }
}
