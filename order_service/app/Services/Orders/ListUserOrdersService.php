<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListUserOrdersService
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function handle(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->with(['items.productSnapshot'])
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
