<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

final readonly class ProductAttributeData
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            value: trim((string) ($data['value'] ?? '')),
        );
    }

    /**
     * @return array{name: string, value: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }
}
