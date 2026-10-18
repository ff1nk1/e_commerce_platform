<?php

namespace App\Listeners;

use App\Contracts\Outboxable;
use App\Services\OutboxWriter;

class WriteOutboxableToOutbox
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
