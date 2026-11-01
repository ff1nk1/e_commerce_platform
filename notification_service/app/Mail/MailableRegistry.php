<?php

declare(strict_types=1);

namespace App\Mail;

use App\DTO\Notifications\NotificationMailData;
use App\Enums\NotificationEventType;
use Illuminate\Contracts\Mail\Mailable;
use InvalidArgumentException;

final class MailableRegistry
{
    public function make(string $eventType, NotificationMailData $mailData): Mailable
    {
        $type = NotificationEventType::tryFrom($eventType);

        throw_if($type === null, InvalidArgumentException::class, "Unknown notification event_type: {$eventType}");

        return match ($type) {
            NotificationEventType::OrderPaid => new OrderPaidMail($mailData),
            NotificationEventType::OrderConfirmed => new OrderConfirmedMail($mailData),
            NotificationEventType::OrderCancelled => new OrderCancelledMail($mailData),
            NotificationEventType::UserRegistered => new WelcomeMail($mailData),
        };
    }

    /**
     * @return list<string>
     */
    public function knownEventTypes(): array
    {
        return array_map(
            static fn (NotificationEventType $type): string => $type->value,
            NotificationEventType::cases(),
        );
    }

    public function supports(string $eventType): bool
    {
        return NotificationEventType::tryFrom($eventType) !== null;
    }
}
