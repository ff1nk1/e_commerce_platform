<?php

declare(strict_types=1);

namespace Shop\Messaging;

use JsonException;
use Ramsey\Uuid\Uuid;

final class EventCodec
{
    /**
     * @throws JsonException
     */
    public function encode(OutboundMessage $message): EncodedMessage
    {
        $messageId = $message->messageId !== null && $message->messageId !== ''
            ? $message->messageId
            : Uuid::uuid4()->toString();

        $body = $message->payload;
        $body['event_type'] = $message->eventType;
        $body['event'] = $message->eventType;

        $headers = [
            'event-type' => $message->eventType,
            'source' => $message->source,
            'message-id' => $messageId,
        ];

        if ($message->sagaId !== null && $message->sagaId !== '') {
            $headers['saga-id'] = $message->sagaId;
        }

        foreach ($message->extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        return new EncodedMessage($message->topic, $message->key, $headers, $body);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function decode(string $topic, array $headers, mixed $body, ?string $key = null): InboundEnvelope
    {
        $normalizedHeaders = $this->stringifyHeaders($headers);
        $payload = $this->decodeBody($body);
        $eventType = $this->eventType($normalizedHeaders, $payload);
        $messageId = $normalizedHeaders['message-id'] ?? '';
        if ($messageId === '') {
            $messageId = hash('sha256', $topic.'|'.($key ?? '').'|'.json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $sagaId = $normalizedHeaders['saga-id'] ?? null;
        if ($sagaId === null && isset($payload['saga_id']) && is_string($payload['saga_id']) && $payload['saga_id'] !== '') {
            $sagaId = $payload['saga_id'];
        }

        return new InboundEnvelope(
            eventType: $eventType,
            messageId: $messageId,
            topic: $topic,
            key: $key,
            sagaId: $sagaId,
            payload: $payload,
            headers: $normalizedHeaders,
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringifyHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (! is_string($name) || $value === null) {
                continue;
            }
            if (is_scalar($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }

        if (! is_string($body) || $body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function eventType(array $headers, array $payload): string
    {
        $header = $headers['event-type'] ?? '';
        if ($header !== '') {
            return $header;
        }

        $fromBody = $payload['event_type'] ?? null;
        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        $legacy = $payload['event'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return '';
    }
}
