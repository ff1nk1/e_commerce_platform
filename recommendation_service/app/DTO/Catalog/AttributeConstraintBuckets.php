<?php

declare(strict_types=1);

namespace App\DTO\Catalog;

/**
 * Typed grouping of attribute constraints into hard / semi-hard / soft buckets.
 */
final readonly class AttributeConstraintBuckets
{
    /**
     * @param  list<AttributeConstraint>  $required  Hard constraints (must match)
     * @param  list<AttributeConstraint>  $preferred  Semi-hard (try hard, then soft fallback)
     * @param  list<AttributeConstraint>  $soft  Soft preferences (re-rank only)
     */
    public function __construct(
        public array $required = [],
        public array $preferred = [],
        public array $soft = [],
    ) {}
}
