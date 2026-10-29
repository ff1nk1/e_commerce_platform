<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class Topics
{
    public const PRODUCT_EVENTS = 'product.events';

    public const CATEGORY_EVENTS = 'category.events';

    public const STOCK_EVENTS = 'stock.events';

    public const CATALOG_EVENTS = 'catalog.events';

    public const ORDER_EVENTS = 'order.events';

    public const USER_EVENTS = 'user.events';

    public const SEARCH_EVENTS = 'search.events';

    public const USER_EVENTS_DLQ = 'user.events.dlq';

    public const ORDER_EVENTS_DLQ = 'order.events.dlq';
}
