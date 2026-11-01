<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Contracts\IdempotencyStore;
use App\DTO\Orders\CreateOrderResult;
use App\DTO\Orders\OrderItemRequest;
use App\DTO\Orders\OrderView;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Messaging\OrderPlaced;
use App\Models\Order;
use App\Models\ProductSnapshot;
use App\ValueObjects\Money;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CreateOrderService
{
    private const LOCK_RETRY_ATTEMPTS = 2;

    private const LOCK_RETRY_SLEEP_MICROS = 50_000;

    public function __construct(
        private IdempotencyStore $idempotency,
        private EventBus $events,
    ) {}

    /**
     * @param  list<OrderItemRequest>  $items
     */
    public function handle(int $userId, string $email, string $name, array $items, string $idempotencyKey): CreateOrderResult
    {
        $scopeKey = $this->idempotency->scopeKey('create', $userId, $idempotencyKey);

        $cached = $this->idempotency->get($scopeKey);
        if ($cached !== null) {
            return new CreateOrderResult($cached['status'], $cached['body']);
        }

        if (! $this->idempotency->acquireLock($scopeKey)) {
            for ($attempt = 0; $attempt < self::LOCK_RETRY_ATTEMPTS; $attempt++) {
                Sleep::usleep(self::LOCK_RETRY_SLEEP_MICROS);
                $cached = $this->idempotency->get($scopeKey);
                if ($cached !== null) {
                    return new CreateOrderResult($cached['status'], $cached['body']);
                }
            }

            throw new ConflictHttpException('Order creation already in progress for this idempotency key.');
        }

        try {
            $cached = $this->idempotency->get($scopeKey);
            if ($cached !== null) {
                return new CreateOrderResult($cached['status'], $cached['body']);
            }

            $order = $this->persistOrder($userId, $email, $name, $items);
            $body = OrderView::fromOrder($order)->toArray();
            $this->idempotency->put($scopeKey, 201, $body);

            return new CreateOrderResult(201, $body);
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    /**
     * @param  list<OrderItemRequest>  $items
     */
    private function persistOrder(int $userId, string $email, string $name, array $items): Order
    {
        return DB::transaction(function () use ($userId, $email, $name, $items) {
            $lineItems = [];
            $outboxItems = [];
            $total = Money::zero();

            $productIds = array_values(array_unique(array_map(
                static fn (OrderItemRequest $item): int => $item->product_id,
                $items
            )));

            $snapshots = ProductSnapshot::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $productId = $item->product_id;
                $quantity = $item->quantity;

                $snapshot = $snapshots->get($productId);
                if ($snapshot === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Product snapshot not found for product_id {$productId}"],
                    ]);
                }

                $unitPrice = Money::of((string) $snapshot->price);
                $lineTotal = $unitPrice->multiply((string) $quantity);
                $total = $total->add($lineTotal);

                $lineItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->amount(),
                ];

                $outboxItems[] = [
                    'product_id' => $productId,
                    'store_id' => (int) $snapshot->store_id,
                    'store_name' => $snapshot->store_name,
                    'category_id' => $snapshot->category_id !== null ? (int) $snapshot->category_id : null,
                    'category_name' => $snapshot->category_name,
                    'category_slug' => $snapshot->category_slug,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->amount(),
                ];
            }

            $sagaId = (string) Str::uuid();

            $order = Order::query()->create([
                'saga_id' => $sagaId,
                'user_id' => $userId,
                'customer_email' => $email,
                'customer_name' => $name,
                'status' => OrderStatus::Pending,
                'total_amount' => $total->amount(),
            ]);

            foreach ($lineItems as $line) {
                $order->items()->create($line);
            }

            $this->events->publish(new OrderPlaced($sagaId));

            event(new OrderCreated([
                'saga_id' => $sagaId,
                'order_id' => $order->id,
                'user_id' => $userId,
                'total_amount' => (string) $order->total_amount,
                'created_at' => $order->created_at->toIso8601String(),
                'items' => $outboxItems,
            ]));

            return $order->load(['items.productSnapshot', 'payments']);
        });
    }
}
