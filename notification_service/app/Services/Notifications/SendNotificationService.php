<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\Notifications\DeliveryResult;
use App\DTO\Notifications\OrderMailData;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Mail\Mailable;

final class SendNotificationService
{
    public function __construct(
        private RetryingMailSender $sender,
    ) {}

    /**
     * Create a new notification — or reuse an existing one (Kafka idempotent reuse) — and deliver it.
     */
    public function handle(
        OrderMailData $data,
        NotificationEventType $eventType,
        ?string $messageId = null,
        ?Notification $existing = null,
    ): DeliveryResult {
        $notification = $existing ?? $this->createNotification($data, $eventType, $messageId);

        return $this->deliver($notification, $data, $eventType);
    }

    private function createNotification(OrderMailData $data, NotificationEventType $eventType, ?string $messageId): Notification
    {
        return Notification::query()->create([
            'message_id' => $messageId,
            'email' => $data->email,
            'name' => $data->name,
            'payload' => $this->payload($data, $eventType),
            'status' => NotificationStatus::Pending->value,
            'retries' => 0,
            'moved_to_dlq' => false,
            'error_message' => null,
            'event_type' => $eventType->value,
        ]);
    }

    private function deliver(Notification $notification, OrderMailData $data, NotificationEventType $eventType): DeliveryResult
    {
        $emailAddress = $data->email !== '' ? $data->email : (string) $notification->email;

        $notification->update([
            'status' => NotificationStatus::Pending->value,
            'error_message' => null,
            'moved_to_dlq' => false,
            'retries' => 0,
            'event_type' => $eventType->value,
            'payload' => $this->payload($data, $eventType),
            'name' => $data->name ?? $notification->name,
            'email' => $emailAddress,
        ]);

        return $this->sender->attemptSend(
            $notification,
            $emailAddress,
            fn (): Mailable => $eventType->mailable($data),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrderMailData $data, NotificationEventType $eventType): array
    {
        $payload = $data->toViewArray();
        $payload['email'] = $data->email;
        $payload['event_type'] = $eventType->value;
        if ($data->name !== null) {
            $payload['name'] = $data->name;
        }

        return $payload;
    }
}
