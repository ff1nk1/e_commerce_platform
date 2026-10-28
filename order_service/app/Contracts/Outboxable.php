<?php

declare(strict_types=1);

namespace App\Contracts;

interface Outboxable
{
    public function outboxTopic(): string;

    public function outboxEventType(): string;

    /**
     * @return array<string, mixed>
     */
    public function outboxPayload(): array;
}
