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
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';
    case StockReserved = 'stock.reserved';
    case StockChanged = 'stock.changed';
    case StockReservationFailed = 'stock.reservation_failed';
    case OrderCreated = 'order.created';
    case StockCommitRequested = 'stock.commit_requested';
    case StockReleaseRequested = 'stock.release_requested';
}
