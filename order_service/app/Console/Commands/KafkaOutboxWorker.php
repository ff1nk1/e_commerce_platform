<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Support\KafkaOutboxHeaders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Junges\Kafka\Facades\Kafka;

class KafkaOutboxWorker extends Command
{
    protected $signature = 'kafka:outbox:work';

    protected $description = 'Читает события из таблицы outbox_events и отправляет их в Kafka';

    public function handle(): int
    {
        $this->info('Запуск Outbox Worker (order-service)...');

        while ($this->shouldKeepRunning()) {
            $processed = $this->processBatch();

            if ($processed === 0) {
                sleep(5);
            }
        }

        return self::SUCCESS;
    }

    /** @phpstan-impure */
    private function shouldKeepRunning(): bool
    {
        return true;
    }

    public function processBatch(): int
    {
        if (! Schema::hasTable('outbox_events')) {
            return 0;
        }

        $events = OutboxEvent::query()->orderBy('created_at', 'asc')->limit(50)->get();

        if ($events->isEmpty()) {
            return 0;
        }

        $published = 0;

        foreach ($events as $event) {
            try {
                $headers = KafkaOutboxHeaders::forEvent($event, 'order-service');

                Kafka::publish(config('kafka.brokers', 'kafka:29092'))
                    ->onTopic($event->topic)
                    ->withConfigOptions([
                        'socket.timeout.ms' => 60000,
                        'message.timeout.ms' => 60000,
                        'request.timeout.ms' => 30000,
                        'retries' => 10,
                        'retry.backoff.ms' => 1000,
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
                    sleep(3);
                }
                break;
            }
        }

        return $published;
    }
}
