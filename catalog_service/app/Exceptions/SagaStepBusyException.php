<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Another worker holds the saga step lock. Rethrow so Kafka auto-commit does not advance.
 */
final class SagaStepBusyException extends RuntimeException {}
