<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Contracts\IdempotencyStore;
use App\DTO\Orders\CreateOrderData;
use App\DTO\Orders\CreateOrderResult;
use App\DTO\Orders\IdempotentResponse;
use App\DTO\Orders\OrderCreatedPayload;
use App\DTO\Orders\OrderItemRequest;
use App\DTO\Orders\OrderLineSnapshot;
use App\DTO\Orders\OrderView;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\ProductSnapshot;
use App\Saga\OrderPlaced;
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

    public function handle(CreateOrderData $data): CreateOrderResult
    {
        $scopeKey = $this->idempotency->scopeKey('create', $data->userId, $data->idempotencyKey);

        $cached = $this->idempotency->get($scopeKey);
        if ($cached instanceof IdempotentResponse) {
            return new CreateOrderResult($cached->status, $cached->body);
        }

        if (! $this->idempotency->acquireLock($scopeKey)) {
            for ($attempt = 0; $attempt < self::LOCK_RETRY_ATTEMPTS; $attempt++) {
                Sleep::usleep(self::LOCK_RETRY_SLEEP_MICROS);
                $cached = $this->idempotency->get($scopeKey);
                if ($cached instanceof IdempotentResponse) {
                    return new CreateOrderResult($cached->status, $cached->body);
                }
            }

            throw new ConflictHttpException('Order creation already in progress for this idempotency key.');
        }

        try {
            $cached = $this->idempotency->get($scopeKey);
            if ($cached instanceof IdempotentResponse) {
                return new CreateOrderResult($cached->status, $cached->body);
            }

            $order = $this->persistOrder($data->userId, $data->email, $data->name, $data->items, $scopeKey);
            $body = OrderView::fromOrder($order)->toArray();

            return new CreateOrderResult(201, $body);
        } finally {
            $this->idempotency->releaseLock($scopeKey);
        }
    }

    /**
     * @param  list<OrderItemRequest>  $items
     */
    private function persistOrder(int $userId, string $email, string $name, array $items, string $scopeKey): Order
    {
        return DB::transaction(function () use ($userId, $email, $name, $items, $scopeKey) {
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

                if ($snapshot->deleted_at !== null) {
                    throw ValidationException::withMessages([
                        'items' => ["Product {$productId} is no longer available"],
                    ]);
                }

                $unitPrice = Money::of((string) $snapshot->price);
                $lineTotal = $unitPrice->multiply((string) $quantity);
                $total = $total->add($lineTotal);

                $outboxItems[] = new OrderLineSnapshot(
                    productId: $productId,
                    storeId: (int) $snapshot->store_id,
                    storeName: $snapshot->store_name,
                    categoryId: $snapshot->category_id !== null ? (int) $snapshot->category_id : null,
                    categoryName: $snapshot->category_name,
                    categorySlug: $snapshot->category_slug,
                    quantity: $quantity,
                    unitPrice: $unitPrice->amount(),
                );
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

            foreach ($outboxItems as $line) {
                $order->items()->create($line->toOrderItemAttributes());
            }

            $this->events->publish(new OrderPlaced($sagaId));

            event(new OrderCreated(new OrderCreatedPayload(
                orderId: (int) $order->id,
                userId: $userId,
                sagaId: $sagaId,
                totalAmount: (string) $order->total_amount,
                createdAt: $order->created_at?->toIso8601String() ?? now()->toIso8601String(),
                items: $outboxItems,
            )));

            $order = $order->load(['items.productSnapshot', 'payments']);

            // Persist the idempotent response in the same transaction as the
            // order and its outbox events, so it can never drift from the DB.
            $this->idempotency->put(
                $scopeKey,
                new IdempotentResponse(201, OrderView::fromOrder($order)->toArray())
            );

            return $order;
        });
    }
}
