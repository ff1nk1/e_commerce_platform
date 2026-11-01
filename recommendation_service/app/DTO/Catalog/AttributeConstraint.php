<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

final readonly class AttributeConstraint
{
    public function __construct(
        public string $name,
        public string $value,
        public bool $valueOnly = false,
    ) {}

    /**
     * @param  array{name?: string, value?: string, value_only?: bool}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            name: trim((string) ($row['name'] ?? '')),
            value: trim((string) ($row['value'] ?? '')),
            valueOnly: (bool) ($row['value_only'] ?? false),
        );
    }

    /**
     * @return array{name: string, value: string, value_only?: true}
     */
    public function toArray(): array
    {
        $row = [
            'name' => $this->name,
            'value' => $this->value,
        ];
        if ($this->valueOnly) {
            $row['value_only'] = true;
        }

        return $row;
    }
}
