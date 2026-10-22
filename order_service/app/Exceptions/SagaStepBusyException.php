<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another worker holds the saga step lock. Rethrow so Kafka auto-commit does not advance.
 */
class SagaStepBusyException extends RuntimeException
{
}
