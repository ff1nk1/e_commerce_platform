<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('conversations:cleanup')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('embeddings:sweep-missing')
    ->hourly()
    ->withoutOverlapping();
