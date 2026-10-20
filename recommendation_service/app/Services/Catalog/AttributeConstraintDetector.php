<?php

namespace App\Services\Catalog;

/**
 * Decides which extracted attributes are hard constraints vs soft preferences.
 */
class AttributeConstraintDetector
{
    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @return array{
     *     required: list<array{name: string, value: string}>,
     *     soft: list<array{name: string, value: string}>
     * }
     */
    public function split(string $userMessage, array $attributes): array
    {
        $exclusive = $this->messageImpliesExclusive($userMessage);
        $required = [];
        $soft = [];

        foreach ($attributes as $attr) {
            $name = trim($attr['name']);
            $value = trim($attr['value']);
            if ($name === '' || $value === '') {
                continue;
            }
            $row = ['name' => $name, 'value' => $value];
            if ($exclusive || $this->isSizeAttributeName($name)) {
                $required[] = $row;
            } else {
                $soft[] = $row;
            }
        }

        return ['required' => $required, 'soft' => $soft];
    }

    public function messageImpliesExclusive(string $message): bool
    {
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($message)));

        return preg_match(
            '/(^|[^\p{L}\p{N}])(только|исключительно|именно|строго|ровно|точно)($|[^\p{L}\p{N}])/u',
            $normalized
        ) === 1;
    }

    public function isSizeAttributeName(string $name): bool
    {
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($name)));

        return str_contains($normalized, 'размер') || str_contains($normalized, 'size');
    }
}
