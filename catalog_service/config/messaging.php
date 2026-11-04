<?php

declare(strict_types=1);

use App\Saga\StockFulfillment;

return [
    'document_store_aggregates' => [
        StockFulfillment::class,
    ],
];
