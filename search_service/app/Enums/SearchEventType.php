<?php

declare(strict_types=1);

namespace App\Enums;

enum SearchEventType: string
{
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case ProductPublished = 'product.published';
    case ProductUnpublished = 'product.unpublished';
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';
    case StockChanged = 'stock.changed';
    case SearchPerformed = 'search.performed';

    public function isProduct(): bool
    {
        return match ($this) {
            self::ProductCreated,
            self::ProductUpdated,
            self::ProductDeleted,
            self::ProductPublished,
            self::ProductUnpublished => true,
            default => false,
        };
    }

    public function isCategory(): bool
    {
        return match ($this) {
            self::CategoryCreated, self::CategoryUpdated, self::CategoryDeleted => true,
            default => false,
        };
    }
}
