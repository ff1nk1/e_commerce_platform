<?php

declare(strict_types=1);
use App\Messaging\OrderFulfillment;

return [
    'document_store_aggregates' => [
        OrderFulfillment::class,
    ],
];
