<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\KafkaEventMovedToDlq;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\OutboundMessage;
use Shop\Messaging\OutboxPublisher;

final class PublishEventToDlq
{
    public function __construct(
        private OutboxPublisher $outbox,
    ) {}

    public function handle(KafkaEventMovedToDlq $event): void
    {
        $message = $event->message;
        $headers = $message->getHeaders();
        $body = $message->getBody();
        $eventType = $headers['event-type'] ?? '';
        if ($eventType === '') {
            $fromBody = $body['event_type'] ?? $body['event'] ?? 'unknown';
            $eventType = is_string($fromBody) && $fromBody !== '' ? $fromBody : 'unknown';
        }
        $key = $message->getKey() ?? '';
        if ($key === '') {
            $key = $eventType;
        }

        $this->outbox->publish(new OutboundMessage(
            topic: $event->dlqTopic,
            key: $key,
            eventType: $eventType,
            payload: $body,
            source: 'notification-service',
            extraHeaders: [
                'dlq-reason' => $event->errorMessage,
                'dlq-failed-at' => now()->toIso8601String(),
            ],
        ));

        Log::error("Сообщение не обработано и перенаправлено в DLQ ({$event->dlqTopic})", [
            'email' => $event->email,
            'error' => $event->errorMessage,
        ]);
    }
}
