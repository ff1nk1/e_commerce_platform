<?php

declare(strict_types=1);

namespace App\DTO\Notifications;

use App\Models\Notification;

final readonly class DeliveryResult
{
    public function __construct(
        public bool $isSent,
        public Notification $notification,
        public string $lastError,
    ) {}

    public static function sent(Notification $notification): self
    {
        return new self(isSent: true, notification: $notification, lastError: '');
    }

    public static function failed(Notification $notification, string $lastError): self
    {
        return new self(isSent: false, notification: $notification, lastError: $lastError);
    }
}
