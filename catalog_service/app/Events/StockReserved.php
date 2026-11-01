<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use App\Enums\CatalogEventType;
use Illuminate\Foundation\Events\Dispatchable;
use Shop\Messaging\Topics;

final class StockReserved implements Outboxable
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
        return Topics::STOCK_EVENTS;
    }

    public function outboxEventType(): string
    {
        return CatalogEventType::StockReserved->value;
    }

    public function outboxPayload(): array
    {
        return $this->payload;
    }

    public function outboxKey(): string
    {
        return isset($this->payload['order_id']) && is_scalar($this->payload['order_id'])
            ? (string) $this->payload['order_id']
            : CatalogEventType::StockReserved->value;
    }
}
