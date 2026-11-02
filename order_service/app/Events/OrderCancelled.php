<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use App\DTO\Orders\OrderNotification;
use App\Enums\OrderEventType;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderCancelled implements Outboxable
{
    use Dispatchable;

    public function __construct(
        public readonly OrderNotification $notification,
    ) {}

    public function outboxTopic(): string
    {
        return 'order.events';
    }

    public function outboxEventType(): string
    {
        return OrderEventType::OrderCancelled->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function outboxPayload(): array
    {
        return $this->notification->toArray();
    }
}
