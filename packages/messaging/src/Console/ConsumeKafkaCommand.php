<?php

declare(strict_types=1);

namespace Shop\Messaging\Console;

use Illuminate\Console\Command;
use Shop\Messaging\KafkaInboundAdapter;

abstract class ConsumeKafkaCommand extends Command
{
    /**
     * @return list<string>
     */
    abstract protected function topics(): array;

    abstract protected function group(): string;

    public function handle(KafkaInboundAdapter $adapter): int
    {
        $topics = $this->topics();
        $group = $this->group();
        $offsetReset = (string) config('kafka.offset_reset', 'latest');

        $this->info('Kafka consumer '.$group.' ['.implode(', ', $topics).'] offset='.$offsetReset);

        try {
            $adapter->run($topics, $group, $offsetReset);
        } catch (\Throwable $e) {
            $this->error('ОШИБКА KAFKA: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
