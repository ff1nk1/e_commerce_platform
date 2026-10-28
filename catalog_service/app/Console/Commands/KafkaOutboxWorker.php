<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Support\KafkaOutboxHeaders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Junges\Kafka\Facades\Kafka;

#[Description('Читает события из таблицы outbox_events и отправляет их в Kafka')]
#[Signature('kafka:outbox:work')]
final class KafkaOutboxWorker extends Command
{
    private const IDLE_SLEEP_SECONDS = 5;

    private const ERROR_SLEEP_SECONDS = 3;

    private const BATCH_LIMIT = 50;

    private const SOCKET_TIMEOUT_MS = 60000;

    private const MESSAGE_TIMEOUT_MS = 60000;

    private const REQUEST_TIMEOUT_MS = 30000;

    private const PUBLISH_RETRIES = 10;

    private const RETRY_BACKOFF_MS = 1000;

    private bool $keepRunning = true;

    public function handle(): int
    {
        $this->info('Запуск Outbox Worker...');

        while ($this->keepRunning) {
            $processed = $this->processBatch();

            if ($processed === 0) {
                Sleep::sleep(self::IDLE_SLEEP_SECONDS);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Publish up to 50 pending outbox rows. Returns how many were published.
     * On Kafka failure the failing row is kept and processing stops for this batch.
     */
    public function processBatch(): int
    {
        if (! Schema::hasTable('outbox_events')) {
            return 0;
        }

        $events = OutboxEvent::query()->oldest()->limit(self::BATCH_LIMIT)->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $published = 0;

        foreach ($events as $event) {
            try {
                $headers = KafkaOutboxHeaders::forEvent($event, 'catalog-service');

                Kafka::publish(config('kafka.brokers', 'kafka:29092'))
                    ->onTopic($event->topic)
                    ->withConfigOptions([
                        'socket.timeout.ms' => self::SOCKET_TIMEOUT_MS,
                        'message.timeout.ms' => self::MESSAGE_TIMEOUT_MS,
                        'request.timeout.ms' => self::REQUEST_TIMEOUT_MS,
                        'retries' => self::PUBLISH_RETRIES,
                        'retry.backoff.ms' => self::RETRY_BACKOFF_MS,
                    ])
                    ->withKafkaKey((string) $event->id)
                    ->withHeaders($headers)
                    ->withBody($event->payload)
                    ->send();

                $event->delete();
                $published++;

                if ($this->output !== null) {
                    $this->info("Отправлено событие: {$event->event_type} в топик {$event->topic}");
                }
            } catch (\Throwable $e) {
                Log::error('Ошибка отправки Outbox события в Kafka', [
                    'event_id' => $event->id,
                    'topic' => $event->topic,
                    'error' => $e->getMessage(),
                ]);

                if (! app()->environment('testing')) {
                    Sleep::sleep(self::ERROR_SLEEP_SECONDS);
                }
                break;
            }
        }

        return $published;
    }
}
