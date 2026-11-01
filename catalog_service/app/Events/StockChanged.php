<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\Outboxable;
use App\Enums\CatalogEventType;
use Illuminate\Foundation\Events\Dispatchable;
use Shop\Messaging\Topics;

final class StockChanged implements Outboxable
{
    use Dispatchable;

    /**
     * @param  array{product_id: int, store_id: int, quantity: int}  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function outboxTopic(): string
    {
        return Topics::CATALOG_EVENTS;
    }

    public function outboxEventType(): string
    {
        return CatalogEventType::StockChanged->value;
    }

    public function outboxPayload(): array
    {
        return $this->payload;
    }

    public function outboxKey(): string
    {
        return (string) $this->payload['product_id'];
    }
}
