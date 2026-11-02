<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;
use Shop\Messaging\Exception\PermanentMessageException;

final class KafkaInboundAdapter implements KafkaConsumerLoop
{
    public function __construct(
        private readonly EventCodec $codec,
        private readonly InboxWriter $inbox,
        private readonly ?KafkaConsumerLoop $loop = null,
        private readonly ?TransactionRunner $transactions = null,
        private readonly ?DeadLetterPublisher $deadLetters = null,
        private readonly ?RetryPolicy $retryPolicy = null,
    ) {}

    /**
     * @param  list<string>  $topics
     */
    public function run(array $topics, string $groupId, string $offsetReset): void
    {
        $loop = $this->loop ?? $this;
        $loop->consume($topics, $groupId, $offsetReset, function (ConsumedRecord $record): void {
            $this->accept($record);
        });
    }

    public function accept(ConsumedRecord $record): void
    {
        try {
            $envelope = $this->codec->decode($record->topic, $record->headers, $record->body, $record->key);
        } catch (PermanentMessageException $e) {
            $this->deadLetter($record, $e->getMessage());

            return;
        }

        if ($envelope->eventType === '') {
            $this->deadLetter($record, 'Missing event type');

            return;
        }

        if (! $this->store($record, $envelope)) {
            $this->deadLetter($record, sprintf('Inbox store failed after %d attempts', $this->retryPolicy()->maxAttempts));

            return;
        }

        $record->commit();
    }

    /**
     * Store the envelope, retrying transient failures with exponential backoff.
     * Returns false when the record should be diverted to the dead-letter topic.
     */
    private function store(ConsumedRecord $record, InboundEnvelope $envelope): bool
    {
        $transactions = $this->transactions ?? new LaravelTransactionRunner;
        $policy = $this->retryPolicy();

        for ($attempt = 1; $attempt <= $policy->maxAttempts; $attempt++) {
            try {
                $transactions->run(function () use ($envelope): void {
                    $this->inbox->store($envelope);
                });

                return true;
            } catch (PermanentMessageException $e) {
                return false;
            } catch (\Throwable $e) {
                $this->log('warning', 'Inbox store failed', [
                    'topic' => $record->topic,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $policy->maxAttempts) {
                    $this->sleep($policy->backoffMs($attempt));
                }
            }
        }

        return false;
    }

    /**
     * Publish the record to its dead-letter topic and commit the original offset
     * only once the broker acknowledged the write. If the DLQ itself is
     * unreachable the exception propagates so the offset stays uncommitted.
     */
    private function deadLetter(ConsumedRecord $record, string $reason): void
    {
        $suffix = $this->dlqSuffix();
        $topic = Topics::dlqFor($record->topic, $suffix);

        $this->log('warning', 'Kafka message diverted to DLQ', [
            'topic' => $record->topic,
            'dlq_topic' => $topic,
            'reason' => $reason,
        ]);

        $this->deadLetters()->publish($record, $topic, $reason);

        $record->commit();
    }

    public function consume(array $topics, string $groupId, string $offsetReset, callable $handler): void
    {
        $brokers = (string) config('kafka.brokers', 'kafka:29092');

        $consumer = Kafka::consumer($topics)
            ->withBrokers($brokers)
            ->withConsumerGroupId($groupId)
            ->usingDeserializer(new NonThrowingJsonDeserializer)
            ->withHandler(function (ConsumerMessage $message, MessageConsumer $consumer) use ($handler): void {
                $key = $message->getKey();
                $record = new ConsumedRecord(
                    topic: (string) $message->getTopicName(),
                    key: is_scalar($key) ? (string) $key : null,
                    headers: $message->getHeaders() ?? [],
                    body: $message->getBody(),
                    commitOffset: static function () use ($consumer, $message): void {
                        $consumer->commit($message);
                    },
                    partition: $message->getPartition(),
                    offset: $message->getOffset(),
                    timestamp: $message->getTimestamp(),
                );
                $handler($record);
            })
            ->withManualCommit()
            ->stopOnFailure()
            ->withOptions([
                'auto.offset.reset' => $offsetReset,
            ])
            ->build();

        $consumer->consume();
    }

    private function deadLetters(): DeadLetterPublisher
    {
        return $this->deadLetters ?? new KafkaDeadLetterPublisher($this->retryPolicy());
    }

    private function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ?? RetryPolicy::fromConfig();
    }

    private function dlqSuffix(): string
    {
        $suffix = $this->config('kafka.consumer.dlq_suffix', Topics::DLQ_SUFFIX);

        return is_string($suffix) && $suffix !== '' ? $suffix : Topics::DLQ_SUFFIX;
    }

    /**
     * Read a config value, tolerating a missing Laravel container (unit tests).
     */
    private function config(string $key, mixed $default = null): mixed
    {
        if (! Facade::getFacadeApplication() instanceof Application) {
            return $default;
        }

        return config($key, $default);
    }

    /**
     * Log when a logger is bound; stay silent otherwise (unit tests).
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (! Facade::getFacadeApplication() instanceof Application) {
            return;
        }

        Log::log($level, $message, $context);
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        Sleep::usleep($milliseconds * 1000);
    }
}
