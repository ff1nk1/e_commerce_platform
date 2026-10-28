<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use Illuminate\Foundation\Events\Dispatchable;

final class StockCommitRequested implements Outboxable
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
        return 'stock.commit_requested';
    }

    public function outboxPayload(): array
    {
        return $this->payload;
    }
}
