<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface TransactionRunner
{
    public function run(callable $callback): void;
}
