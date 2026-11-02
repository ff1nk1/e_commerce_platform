<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

/**
 * A typed list of catalog attribute constraints (name/value pairs).
 */
final readonly class AttributeCollection
{
    /**
     * @param  list<AttributeConstraint>  $items
     */
    public function __construct(
        public array $items = [],
    ) {}

    /**
     * @param  list<AttributeConstraint>  $items
     */
    public static function fromConstraints(array $items): self
    {
        return new self($items);
    }

    /**
     * @return list<array{name: string, value: string, value_only?: true}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (AttributeConstraint $constraint): array => $constraint->toArray(),
            $this->items,
        );
    }
}
