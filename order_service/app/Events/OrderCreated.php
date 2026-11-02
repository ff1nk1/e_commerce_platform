<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use App\DTO\Orders\OrderCreatedPayload;
use App\Enums\OrderEventType;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderCreated implements Outboxable
{
    use Dispatchable;

    public function __construct(
        public readonly OrderCreatedPayload $payload,
    ) {}

    public function outboxTopic(): string
    {
        return 'order.events';
    }

    public function outboxEventType(): string
    {
        return OrderEventType::OrderCreated->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function outboxPayload(): array
    {
        return $this->payload->toArray();
    }
}
