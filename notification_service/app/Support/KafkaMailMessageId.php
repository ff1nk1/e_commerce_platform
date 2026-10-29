<?php

declare(strict_types=1);

namespace App\Support;

use Shop\Messaging\EnvelopeMessage;

final class KafkaMailMessageId
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function resolve(
        EnvelopeMessage $message,
        string $sourceTopic,
        string $eventType,
        array $body,
    ): string {
        $headerKey = (string) config('kafka.message_id_key', 'laravel-kafka::message-id');
        $headers = $message->getHeaders();
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

        return "{$sourceTopic}:{$eventType}:{$email}:{$bodyHash}";
    }
}
