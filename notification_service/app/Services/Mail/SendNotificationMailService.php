<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\DTO\Notifications\MailDeliveryResult;
use App\Models\EmailLog;

final class SendNotificationMailService
{
    public function __construct(
        private MailSender $mailSender,
    ) {}

    /**
     * Create a new EmailLog and send, or deliver an existing log (Kafka idempotent reuse).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        array $payload,
        string $eventType,
        ?string $messageId = null,
        ?EmailLog $existing = null,
    ): MailDeliveryResult {
        if ($existing instanceof EmailLog) {
            return $this->mailSender->deliverExisting($existing, $payload, $eventType);
        }

        return $this->mailSender->sendNew($payload, $eventType, $messageId);
    }
}
