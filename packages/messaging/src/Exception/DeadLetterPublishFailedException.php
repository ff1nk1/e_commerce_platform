<?php

declare(strict_types=1);

namespace Shop\Messaging\Exception;

use RuntimeException;

/**
 * Raised when the adapter could not publish a dead letter after exhausting its
 * retries. The original offset must stay uncommitted so the message is redelivered.
 */
final class DeadLetterPublishFailedException extends RuntimeException {}
