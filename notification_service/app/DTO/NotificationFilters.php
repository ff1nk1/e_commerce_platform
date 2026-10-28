<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class NotificationFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $email = null,
        public ?string $eventType = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $perPage = 20,
    ) {}
}
