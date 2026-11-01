<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Outboxable;
use App\Kafka\CatalogOutbox;

final class WriteOutboxableToOutbox
{
    public function __construct(
        private CatalogOutbox $outbox,
    ) {}

    public function handle(Outboxable $event): void
    {
        $this->outbox->publish(
            $event->outboxTopic(),
            $event->outboxEventType(),
            $event->outboxPayload(),
            $event->outboxKey(),
        );
    }
}
