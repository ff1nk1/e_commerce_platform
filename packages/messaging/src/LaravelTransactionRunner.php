<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Support\Facades\DB;

final class LaravelTransactionRunner implements TransactionRunner
{
    public function run(callable $callback): void
    {
        DB::transaction($callback);
    }
}
