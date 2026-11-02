<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use App\DTO\Orders\StockRequest;
use App\Enums\OrderEventType;
use Illuminate\Foundation\Events\Dispatchable;

final class StockReleaseRequested implements Outboxable
{
    use Dispatchable;

    public function __construct(
        public readonly StockRequest $request,
    ) {}

    public function outboxTopic(): string
    {
        return 'order.events';
    }

    public function outboxEventType(): string
    {
        return OrderEventType::StockReleaseRequested->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function outboxPayload(): array
    {
        return $this->request->toArray();
    }
}
