<?php

namespace App\Events;

use App\Contracts\Outboxable;
use Illuminate\Foundation\Events\Dispatchable;

class OrderCancelled implements Outboxable
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function outboxTopic(): string
    {
        return 'order.events';
    }

    public function outboxEventType(): string
    {
        return 'order.cancelled';
    }

    public function outboxPayload(): array
    {
        return $this->payload;
    }
}
