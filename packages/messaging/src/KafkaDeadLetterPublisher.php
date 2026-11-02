<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\Exception\DeadLetterPublishFailedException;
use Throwable;

/**
 * Publishes dead letters through the same broker the adapter consumes from.
 *
 * The producer waits for all in-sync replicas (acks=all) and idempotent writes,
 * and send() blocks until the broker acknowledges the record. The adapter only
 * commits the original offset after this call returns, giving at-least-once
 * delivery into the DLQ.
 */
final class KafkaDeadLetterPublisher implements DeadLetterPublisher
{
    public function __construct(
        private readonly RetryPolicy $retryPolicy = new RetryPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function producerOptions(): array
    {
        return [
            'acks' => 'all',
            'enable.idempotence' => true,
            'socket.timeout.ms' => 60000,
            'message.timeout.ms' => 60000,
            'request.timeout.ms' => 30000,
            'retries' => 10,
            'retry.backoff.ms' => 1000,
        ];
    }

    public function publish(ConsumedRecord $record, string $topic, string $reason): void
    {
        $brokers = (string) config('kafka.brokers', 'kafka:29092');
        $headers = $this->headers($record, $reason);
        $body = $this->body($record->body);

        $last = null;
        for ($attempt = 1; $attempt <= $this->retryPolicy->dlqAttempts; $attempt++) {
            try {
                $builder = Kafka::publish($brokers)
                    ->onTopic($topic)
                    ->withConfigOptions(self::producerOptions())
                    ->usingSerializer(new RawMessageSerializer)
                    ->withKafkaKey($record->key ?? '')
                    ->withHeaders($headers)
                    ->withBody($body);

                $builder->send();

                return;
            } catch (Throwable $e) {
                $last = $e;
                $this->log($topic, $attempt, $reason);

                if ($attempt < $this->retryPolicy->dlqAttempts) {
                    $this->sleep($this->retryPolicy->backoffMs($attempt));
                }
            }
        }

        throw new DeadLetterPublishFailedException(
            sprintf('Could not publish dead letter to "%s": %s', $topic, $last?->getMessage() ?? 'unknown error'),
            0,
            $last,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(ConsumedRecord $record, string $reason): array
    {
        $headers = [];
        foreach ($record->headers as $name => $value) {
            if (is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        $headers['dlq-reason'] = $reason;
        $headers['dlq-failed-at'] = gmdate('c');
        $headers['dlq-source-topic'] = $record->topic;
        $headers['dlq-source-partition'] = (string) ($record->partition ?? '');
        $headers['dlq-source-offset'] = (string) ($record->offset ?? '');
        $headers['dlq-attempts'] = (string) $record->attempts;

        return $headers;
    }

    private function body(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        $encoded = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);

        return $encoded === false ? '' : $encoded;
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        Sleep::usleep($milliseconds * 1000);
    }

    private function log(string $topic, int $attempt, string $reason): void
    {
        if (! Facade::getFacadeApplication()) {
            return;
        }

        Log::warning('DLQ publish attempt failed', [
            'topic' => $topic,
            'attempt' => $attempt,
            'reason' => $reason,
        ]);
    }
}
