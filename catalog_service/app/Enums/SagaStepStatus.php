<?php

declare(strict_types=1);

namespace App\Enums;

enum SagaStepStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
