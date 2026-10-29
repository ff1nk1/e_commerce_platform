<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class CatalogPayloadNormalizer
{
    public const MAX_NAME_LENGTH = 500;

    public const MAX_DESCRIPTION_LENGTH = 5000;

    public const MAX_IMAGE_URL_LENGTH = 2048;

    public const MAX_ATTRIBUTES = 50;

    public const MAX_ATTR_FIELD_LENGTH = 64;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = mb_substr((string) $data['name'], 0, self::MAX_NAME_LENGTH);
        }

        if (array_key_exists('description', $data) && is_string($data['description'])) {
            $data['description'] = mb_substr($data['description'], 0, self::MAX_DESCRIPTION_LENGTH);
        }

        if (array_key_exists('image_url', $data)) {
            $data['image_url'] = $this->imageUrl($data['image_url']);
        }

        if (array_key_exists('attributes', $data)) {
            $data['attributes'] = $this->attributes($data['attributes']);
        }

        return $data;
    }

    private function imageUrl(mixed $imageUrl): ?string
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            return null;
        }

        $imageUrl = mb_substr($imageUrl, 0, self::MAX_IMAGE_URL_LENGTH);
        if (! preg_match('#^https?://#i', $imageUrl)) {
            return null;
        }

        return $imageUrl;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function attributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];
        foreach ($attributes as $row) {
            if (count($normalized) >= self::MAX_ATTRIBUTES) {
                break;
            }
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $normalized[] = [
                'name' => mb_substr($name, 0, self::MAX_ATTR_FIELD_LENGTH),
                'value' => mb_substr($value, 0, self::MAX_ATTR_FIELD_LENGTH),
            ];
        }

        return $normalized;
    }
}
