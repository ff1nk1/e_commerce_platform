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
        $idFromHeader = $headers[$headerKey] ?? null;
        if (is_string($idFromHeader) && $idFromHeader !== '') {
            return $idFromHeader;
        }

        $kafkaKey = $message->getKey();
        $idFromKafkaKey = ($kafkaKey !== null && $kafkaKey !== '')
            ? "{$sourceTopic}:{$kafkaKey}:{$eventType}"
            : null;
        if ($idFromKafkaKey !== null) {
            return $idFromKafkaKey;
        }

        $email = (string) ($body['email'] ?? '');
        $bodyHash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        $idFromBodyHash = "{$sourceTopic}:{$eventType}:{$email}:{$bodyHash}";

        return $idFromBodyHash;
    }
}
