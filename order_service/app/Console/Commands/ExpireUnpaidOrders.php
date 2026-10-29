<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Order\CancelOrderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Cancel orders left unpaid past the payment timeout')]
#[Signature('orders:expire-unpaid')]
final class ExpireUnpaidOrders extends Command
{
    public function handle(CancelOrderService $orders): int
    {
        $expired = $orders->expireUnpaid();
        $this->info("Cancelled {$expired} unpaid orders.");

        return self::SUCCESS;
    }
}
