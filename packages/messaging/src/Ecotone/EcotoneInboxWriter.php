<?php

declare(strict_types=1);

namespace Shop\Messaging\Ecotone;

use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\InboxWriter;

final class EcotoneInboxWriter implements InboxWriter
{
    public function __construct(
        private readonly EventBus $bus,
    ) {}

    public function store(InboundEnvelope $envelope): void
    {
        $inserted = DB::table('messaging_inbox_dedup')->insertOrIgnore([
            'message_id' => $envelope->messageId,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $this->bus->publish($envelope);
    }
}
