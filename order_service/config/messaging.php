<?php

declare(strict_types=1);
use App\Saga\OrderFulfillment;

return [
    'document_store_aggregates' => [
        OrderFulfillment::class,
    ],
];
