<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OutboxQueue
{
    /**
     * @return list<StoredOutboxMessage>
     */
    public static function all(string $channel = 'kafka-outbox'): array
    {
        if (! Schema::hasTable('enqueue')) {
            return [];
        }

        $stored = [];
        $rows = DB::table('enqueue')->where('queue', $channel)->orderBy('published_at')->get();
        foreach ($rows as $row) {
            $message = self::decode((string) $row->body);
            if (! $message instanceof OutboundMessage) {
                continue;
            }
            $stored[] = new StoredOutboxMessage(
                id: (string) $row->id,
                topic: $message->topic,
                event_type: $message->eventType,
                payload: $message->payload,
                message: $message,
            );
        }

        return $stored;
    }

    /**
     * @return list<StoredOutboxMessage>
     */
    public static function ofType(string $eventType, string $channel = 'kafka-outbox'): array
    {
        return array_values(array_filter(
            self::all($channel),
            static fn (StoredOutboxMessage $message): bool => $message->event_type === $eventType,
        ));
    }

    public static function first(string $eventType, string $channel = 'kafka-outbox'): ?StoredOutboxMessage
    {
        return self::ofType($eventType, $channel)[0] ?? null;
    }

    public static function count(?string $eventType = null, string $channel = 'kafka-outbox'): int
    {
        if ($eventType === null) {
            return count(self::all($channel));
        }

        return count(self::ofType($eventType, $channel));
    }

    public static function flush(string $channel = 'kafka-outbox'): int
    {
        if (! Schema::hasTable('enqueue')) {
            return 0;
        }

        $pending = DB::table('enqueue')->where('queue', $channel)->count();
        if ($pending === 0) {
            return 0;
        }

        app(ConfiguredMessagingSystem::class)->run(
            $channel,
            ExecutionPollingMetadata::createWithDefaults()
                ->withHandledMessageLimit($pending)
                ->withFinishWhenNoMessages(true)
                ->withStopOnError(true),
        );

        $left = DB::table('enqueue')->where('queue', $channel)->count();

        return $pending - $left;
    }

    private static function decode(string $body): ?OutboundMessage
    {
        foreach (self::bodyCandidates($body) as $candidate) {
            $message = self::decodeCandidate($candidate);
            if ($message instanceof OutboundMessage) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function bodyCandidates(string $body): array
    {
        $candidates = [$body];
        $trimmed = trim($body);
        if ($trimmed !== '' && ($trimmed[0] === '"' || str_contains($trimmed, '\\"'))) {
            $json = $trimmed[0] === '"' ? $trimmed : '"'.$trimmed.'"';
            $decoded = json_decode($json, true);
            if (is_string($decoded)) {
                $candidates[] = $decoded;
            }
        }

        return $candidates;
    }

    private static function decodeCandidate(string $body): ?OutboundMessage
    {
        if ($body === '') {
            return null;
        }

        if ($body[0] === '{' || $body[0] === '[') {
            $decoded = json_decode($body, true);
            if (! is_array($decoded) || ! isset($decoded['topic'], $decoded['eventType'], $decoded['key'])) {
                return null;
            }

            return new OutboundMessage(
                topic: (string) $decoded['topic'],
                key: (string) $decoded['key'],
                eventType: (string) $decoded['eventType'],
                payload: is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
                source: (string) ($decoded['source'] ?? ''),
                sagaId: isset($decoded['sagaId']) && is_string($decoded['sagaId']) ? $decoded['sagaId'] : null,
                messageId: isset($decoded['messageId']) && is_string($decoded['messageId']) ? $decoded['messageId'] : null,
                extraHeaders: is_array($decoded['extraHeaders'] ?? null) ? $decoded['extraHeaders'] : [],
            );
        }

        if (! str_starts_with($body, 'O:')) {
            return null;
        }

        try {
            $decoded = unserialize($body, ['allowed_classes' => [OutboundMessage::class]]);
        } catch (\Throwable) {
            return null;
        }

        return $decoded instanceof OutboundMessage ? $decoded : null;
    }
}
