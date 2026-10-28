<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\ProductSnapshot;

trait CreatesOrderFixtures
{
    protected function seedSnapshot(
        int $id = 501,
        string $name = 'Integration Item',
        string $price = '25.00',
        int $storeId = 1,
    ): ProductSnapshot {
        return ProductSnapshot::query()->updateOrCreate(
            ['id' => $id],
            [
                'name' => $name,
                'price' => $price,
                'store_id' => $storeId,
            ]
        );
    }

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{user_id: int, email: string, name: string, items: list<array{product_id: int, quantity: int}>}
     */
    protected function orderCreatePayload(
        int $userId = 42,
        string $email = 'integration@example.com',
        string $name = 'Integration Buyer',
        array $items = [['product_id' => 501, 'quantity' => 1]],
    ): array {
        return [
            'user_id' => $userId,
            'email' => $email,
            'name' => $name,
            'items' => $items,
        ];
    }
}
