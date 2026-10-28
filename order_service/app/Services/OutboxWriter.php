<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OutboxEvent;

final class OutboxWriter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function write(string $topic, string $eventType, array $payload): OutboxEvent
    {
        $payload['event_type'] = $eventType;

        return OutboxEvent::query()->create([
            'topic' => $topic,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }
}
