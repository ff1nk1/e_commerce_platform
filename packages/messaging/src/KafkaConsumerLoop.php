<?php

declare(strict_types=1);

namespace Shop\Messaging;

interface KafkaConsumerLoop
{
    /**
     * @param  list<string>  $topics
     * @param  callable(ConsumedRecord): void  $handler
     */
    public function consume(array $topics, string $groupId, string $offsetReset, callable $handler): void;
}
