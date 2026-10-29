<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Support\Facades\DB;

interface TransactionRunner
{
    public function run(callable $callback): void;
}
