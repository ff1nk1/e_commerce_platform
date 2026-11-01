<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAllOrdersService
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function handle(int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->with(['items.productSnapshot'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
