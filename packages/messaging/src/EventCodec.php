<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Ramsey\Uuid\Uuid;
use Shop\Messaging\Exception\PermanentMessageException;

final class EventCodec
{
    /**
     * @throws \JsonException
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
     * Decode a consumed record into an inbound envelope. A malformed (non-JSON)
     * body is a permanent error and raises PermanentMessageException so the
     * adapter can divert the message to a dead-letter topic instead of losing
     * it or blocking the partition forever.
     *
     * @param  array<string, mixed>  $headers
     *
     * @throws PermanentMessageException
     */
    public function decode(string $topic, array $headers, mixed $body, ?string $key = null): InboundEnvelope
    {
        $normalizedHeaders = $this->stringifyHeaders($headers);
        $payload = $this->decodeBody($body);
        $eventType = $this->eventType($normalizedHeaders, $payload);
        $messageId = $this->messageId($topic, $key, $normalizedHeaders, $payload);

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
     * Resolve the event type from the header first, then the body. Returns an
     * empty string when the message carries none, which the adapter treats as a
     * dead-letter condition rather than a silent drop.
     *
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

    /**
     * Build a stable identity for deduplication. The header wins; otherwise the
     * payload is hashed. The hash never throws on non-UTF8 bytes, keeping such
     * messages processable rather than turning them into poison pills.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    private function messageId(string $topic, ?string $key, array $headers, array $payload): string
    {
        $header = $headers['message-id'] ?? '';
        if ($header !== '') {
            return $header;
        }

        $encoded = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        return hash('sha256', $topic.'|'.($key ?? '').'|'.($encoded === false ? '' : $encoded));
    }

    /**
     * Normalise, lower-case and stringify incoming Kafka headers.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function stringifyHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_scalar($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * Decode the body to an array. Empty or null bodies yield an empty array;
     * a non-empty string that is not valid JSON is a permanent error.
     *
     * @return array<string, mixed>
     *
     * @throws PermanentMessageException
     */
    private function decodeBody(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }

        if (! is_string($body) || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        throw_unless(is_array($decoded), PermanentMessageException::class, 'Malformed JSON body');

        return $decoded;
    }
}
