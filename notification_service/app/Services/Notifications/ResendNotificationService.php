<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\Notifications\DeliveryResult;
use App\DTO\Notifications\OrderMailData;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Mail\Mailable;
use InvalidArgumentException;

final class ResendNotificationService
{
    public function __construct(
        private RetryingMailSender $sender,
    ) {}

    /**
     * Admin resend of an existing notification.
     */
    public function handle(Notification $notification): DeliveryResult
    {
        throw_if($notification->status !== NotificationStatus::Failed->value, InvalidArgumentException::class, 'Повторная отправка доступна только для записей со статусом failed.');

        $eventType = $this->resolveEventTypeForResend($notification);
        throw_if(! $eventType instanceof NotificationEventType, InvalidArgumentException::class, 'У записи нет event_type, переотправка невозможна.');

        $payload = is_array($notification->payload) ? $notification->payload : [];
        $payload['email'] = $notification->email;
        $payload['event_type'] = $eventType->value;
        if ($notification->name !== null && $notification->name !== '') {
            $payload['name'] = $notification->name;
        }

        $notification->update([
            'status' => NotificationStatus::Pending->value,
            'error_message' => null,
            'moved_to_dlq' => false,
            'retries' => 0,
            'event_type' => $eventType->value,
            'payload' => $payload,
        ]);

        $result = $this->sender->attemptSend(
            $notification,
            (string) $notification->email,
            fn (): Mailable => $eventType->mailable(OrderMailData::fromArray($payload)),
        );

        if (! $result->isSent) {
            $errorMessage = 'Ошибка отправки из админки: '.$result->lastError;
            $notification->update([
                'status' => NotificationStatus::Failed->value,
                'error_message' => $errorMessage,
            ]);

            return DeliveryResult::failed($notification, $errorMessage);
        }

        return $result;
    }

    /**
     * @return NotificationEventType|null null when order mail cannot be uniquely typed
     */
    private function resolveEventTypeForResend(Notification $notification): ?NotificationEventType
    {
        $storedEventType = $notification->event_type;
        if (is_string($storedEventType) && $storedEventType !== '') {
            return NotificationEventType::tryFrom($storedEventType);
        }

        $payload = is_array($notification->payload) ? $notification->payload : [];
        $eventTypeFromPayload = $payload['event_type'] ?? null;
        if (is_string($eventTypeFromPayload) && $eventTypeFromPayload !== '') {
            return NotificationEventType::tryFrom($eventTypeFromPayload);
        }

        if (isset($payload['order_id'])) {
            // order.paid vs order.confirmed cannot be told apart from payload alone.
            return null;
        }

        return NotificationEventType::UserRegistered;
    }
}
