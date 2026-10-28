<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Junges\Kafka\Contracts\ConsumerMessage;

final class KafkaEventMovedToDlq
{
    use Dispatchable;

    public function __construct(
        public readonly ConsumerMessage $message,
        public readonly string $errorMessage,
        public readonly string $email,
        public readonly string $dlqTopic,
    ) {}
}
