<?php

namespace App\Support;

use Junges\Kafka\Contracts\ConsumerMessage;

final class KafkaMailMessageId
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function resolve(
        ConsumerMessage $message,
        string $sourceTopic,
        string $eventType,
        array $body,
    ): string {
        $headerKey = (string) config('kafka.message_id_key', 'laravel-kafka::message-id');
        $headers = $message->getHeaders() ?? [];
        $fromHeader = $headers[$headerKey] ?? null;
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }

        $kafkaKey = $message->getKey();
        if ($kafkaKey !== null && $kafkaKey !== '') {
            return "{$sourceTopic}:{$kafkaKey}:{$eventType}";
        }

        $email = (string) ($body['email'] ?? '');
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        return "{$sourceTopic}:{$eventType}:{$email}:{$hash}";
    }
}
