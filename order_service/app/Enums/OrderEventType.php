<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderEventType: string
{
    case OrderCreated = 'order.created';
    case OrderCancelled = 'order.cancelled';
    case OrderPaid = 'order.paid';
    case OrderShipped = 'order.shipped';
    case OrderConfirmed = 'order.confirmed';
    case StockCommitRequested = 'stock.commit_requested';
    case StockReleaseRequested = 'stock.release_requested';
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case StockReserved = 'stock.reserved';
    case StockReservationFailed = 'stock.reservation_failed';
}
