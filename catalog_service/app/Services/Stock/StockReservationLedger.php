<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\DTO\Stock\StockLine;
use App\Enums\StockReservationStatus;
use App\Exceptions\InsufficientStockReservationException;
use App\Exceptions\MissingStockReservationException;
use App\Models\Stock;
use App\Models\StockReservation;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class StockReservationLedger
{
    /**
     * @return array{product_id: int, store_id: int, quantity: int}
     */
    public function stockChangedPayload(Stock $stock): array
    {
        return [
            'product_id' => $stock->product_id,
            'store_id' => $stock->store_id,
            // Downstream (e.g. recommendation) treats this as sellable/available qty.
            'quantity' => $stock->available,
        ];
    }

    public function settleHeld(int|string $orderId, StockLine $item, StockReservationStatus $nextStatus, bool $decrementQuantity): Stock
    {
        $orderKey = $this->orderKey($orderId);
        $productId = $item->productId;
        $storeId = $item->storeId;
        $requested = $item->quantity;

        $stock = Stock::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        $reservation = StockReservation::query()
            ->where('order_id', $orderKey)
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->where('status', StockReservationStatus::Held)
            ->lockForUpdate()
            ->first();

        throw_if(
            ! $stock instanceof Stock || ! $reservation instanceof StockReservation,
            MissingStockReservationException::class,
            "No held reservation for order {$orderKey}, product {$productId}, store {$storeId}."
        );

        if ($reservation->quantity !== $requested || $stock->reserved < $requested) {
            throw new InsufficientStockReservationException(
                "Held {$reservation->quantity} (counter {$stock->reserved}) for order {$orderKey}, requested {$requested}."
            );
        }

        if ($decrementQuantity && $stock->quantity < $requested) {
            throw new InsufficientStockReservationException(
                "Physical quantity {$stock->quantity} is below commit of {$requested} for order {$orderKey}."
            );
        }

        $reservation->status = $nextStatus;
        $reservation->save();

        if ($decrementQuantity) {
            $stock->quantity -= $requested;
        }
        $stock->reserved -= $requested;
        $stock->save();

        return $stock;
    }

    /**
     * Join a PDO transaction Ecotone already opened, or start a Laravel one.
     *
     * Inbox workers begin the transaction through Doctrine, so Laravel's
     * counter stays at 0. A nested begin then throws "already an active transaction".
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinTransaction(Closure $callback): mixed
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();

        if ($connection->transactionLevel() === 0 && $pdo->inTransaction()) {
            return $callback();
        }

        return $connection->transaction(function (Connection $connection) use ($callback) {
            return $callback();
        });
    }

    public function orderKey(int|string $orderId): int
    {
        if (is_int($orderId) && $orderId >= 1) {
            return $orderId;
        }

        if (is_string($orderId) && ctype_digit($orderId) && (int) $orderId >= 1) {
            return (int) $orderId;
        }

        throw new MissingStockReservationException('Order id is not a positive integer.');
    }
}
