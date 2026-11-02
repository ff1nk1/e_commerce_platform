<?php

declare(strict_types=1);

namespace Shop\Messaging;

final class Topics
{
    public const DLQ_SUFFIX = '.dlq';

    public const PRODUCT_EVENTS = 'product.events';

    public const CATEGORY_EVENTS = 'category.events';

    public const STOCK_EVENTS = 'stock.events';

    public const CATALOG_EVENTS = 'catalog.events';

    public const ORDER_EVENTS = 'order.events';

    public const USER_EVENTS = 'user.events';

    public const SEARCH_EVENTS = 'search.events';

    public const USER_EVENTS_DLQ = 'user.events.dlq';

    public const ORDER_EVENTS_DLQ = 'order.events.dlq';

    /**
     * Dead-letter topic for a source topic. Never doubles the suffix, so it is
     * safe to call for a topic that is already a DLQ.
     */
    public static function dlqFor(string $topic, string $suffix = self::DLQ_SUFFIX): string
    {
        if ($suffix === '') {
            $suffix = self::DLQ_SUFFIX;
        }

        return str_ends_with($topic, $suffix) ? $topic : $topic.$suffix;
    }
}
