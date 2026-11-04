<?php

declare(strict_types=1);

namespace App\DTO\Stock;

/**
 * A settlement line (release/commit) carries only the product and quantity.
 *
 * The warehouse (store_id) is owned by the catalog: it is read from the held
 * `stock_reservations` row, never from the order snapshot, so a product changing
 * store between reserve and release/commit cannot misroute the settlement.
 */
final readonly class SettlementLine
{
    public function __construct(
        public int $productId,
        public int $quantity,
    ) {}
}
