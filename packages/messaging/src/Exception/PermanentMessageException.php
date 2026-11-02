<?php

declare(strict_types=1);

namespace Shop\Messaging\Exception;

use RuntimeException;

/**
 * Raised when a consumed message can never be processed successfully, for
 * example a malformed JSON body. Retrying such a message only blocks the
 * partition, so the adapter diverts it to a dead-letter topic instead.
 */
final class PermanentMessageException extends RuntimeException {}
