<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\KafkaEventMovedToDlq;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;

final class PublishEventToDlq
{
    public function handle(KafkaEventMovedToDlq $event): void
    {
        $message = $event->message;

        try {
            Kafka::publish(config('kafka.brokers', 'kafka:29092'))
                ->onTopic($event->dlqTopic)
                ->withKafkaKey((string) $message->getKey())
                ->withHeaders(array_merge($message->getHeaders() ?? [], [
                    'dlq-reason' => $event->errorMessage,
                    'dlq-failed-at' => now()->toIso8601String(),
                ]))
                ->withBody($message->getBody())
                ->send();

            Log::error("Сообщение не обработано и перенаправлено в DLQ ({$event->dlqTopic})", [
                'email' => $event->email,
                'error' => $event->errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::critical('Не удалось опубликовать сообщение в DLQ топик: '.$e->getMessage());
        }
    }
}
