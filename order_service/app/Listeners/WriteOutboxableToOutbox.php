<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\Outboxable;
use App\Services\OutboxWriter;

final class WriteOutboxableToOutbox
{
    public function __construct(
        private OutboxWriter $outbox,
    ) {}

    public function handle(Outboxable $event): void
    {
        $this->outbox->write(
            $event->outboxTopic(),
            $event->outboxEventType(),
            $event->outboxPayload()
        );
    }
}
