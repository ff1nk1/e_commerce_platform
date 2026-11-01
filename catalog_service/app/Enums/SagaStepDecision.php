<?php

declare(strict_types=1);

namespace App\Enums;

enum SagaStepDecision: string
{
    case Skip = 'skip';
    case Busy = 'busy';
    case Run = 'run';
}
