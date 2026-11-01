<?php

declare(strict_types=1);

namespace App\Enums;

enum CatalogEventType: string
{
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case ProductPublished = 'product.published';
    case ProductUnpublished = 'product.unpublished';
    case StockChanged = 'stock.changed';
}
