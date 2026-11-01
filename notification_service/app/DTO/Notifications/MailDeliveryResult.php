<?php

declare(strict_types=1);

namespace App\DTO\Notifications;

use App\Models\EmailLog;

final readonly class MailDeliveryResult
{
    public function __construct(
        public bool $isSent,
        public EmailLog $emailLog,
        public string $lastError,
    ) {}
}
