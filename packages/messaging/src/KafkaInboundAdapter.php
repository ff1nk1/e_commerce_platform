<?php

declare(strict_types=1);

namespace Shop\Messaging;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;

final class KafkaInboundAdapter implements KafkaConsumerLoop
{
    public function __construct(
        private readonly EventCodec $codec,
        private readonly InboxWriter $inbox,
        private readonly ?KafkaConsumerLoop $loop = null,
        private readonly ?TransactionRunner $transactions = null,
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
        $envelope = $this->codec->decode($record->topic, $record->headers, $record->body, $record->key);
        if ($envelope->eventType === '') {
            Log::warning('Kafka message dropped: missing event type', [
                'topic' => $record->topic,
            ]);
            $record->commit();

            return;
        }

        $transactions = $this->transactions ?? new LaravelTransactionRunner;
        $transactions->run(function () use ($envelope): void {
            $this->inbox->store($envelope);
        });

        $record->commit();
    }

    public function consume(array $topics, string $groupId, string $offsetReset, callable $handler): void
    {
        $brokers = (string) config('kafka.brokers', 'kafka:29092');

        $consumer = Kafka::consumer($topics)
            ->withBrokers($brokers)
            ->withConsumerGroupId($groupId)
            ->withHandler(function (ConsumerMessage $message, MessageConsumer $consumer) use ($handler): void {
                $key = $message->getKey();
                $record = new ConsumedRecord(
                    topic: $message->getTopicName(),
                    key: is_scalar($key) ? (string) $key : null,
                    headers: $message->getHeaders() ?? [],
                    body: $message->getBody(),
                    commitOffset: static function () use ($consumer, $message): void {
                        $consumer->commit($message);
                    },
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
}
