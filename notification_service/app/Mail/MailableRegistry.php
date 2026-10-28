<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Contracts\Mail\Mailable;
use InvalidArgumentException;

final class MailableRegistry
{
    /**
     * @var array<string, class-string<Mailable>>
     */
    private const MAP = [
        'order.paid' => OrderPaidMail::class,
        'order.confirmed' => OrderConfirmedMail::class,
        'order.cancelled' => OrderCancelledMail::class,
        'user.registered' => WelcomeMail::class,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function make(string $eventType, array $payload): Mailable
    {
        throw_if($eventType === '' || ! isset(self::MAP[$eventType]), InvalidArgumentException::class, "Unknown notification event_type: {$eventType}");

        $class = self::MAP[$eventType];

        return new $class($payload);
    }

    /**
     * @return list<string>
     */
    public function knownEventTypes(): array
    {
        return array_keys(self::MAP);
    }

    public function supports(string $eventType): bool
    {
        return isset(self::MAP[$eventType]);
    }
}
