<?php

declare(strict_types=1);

namespace App\Kafka\Handlers;

use App\DTO\Notifications\OrderMailData;
use App\Enums\NotificationEventType;
use App\Enums\NotificationStatus;
use App\Events\KafkaEventMovedToDlq;
use App\Models\Notification;
use App\Services\Notifications\SendNotificationService;
use App\Support\KafkaMailMessageId;
use App\ValueObjects\AllowedEventTypes;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class NotificationEventsHandler
{
    public function __construct(
        private SendNotificationService $delivery,
    ) {}

    public function handle(
        EnvelopeMessage $message,
        AllowedEventTypes $allowedTypes,
        string $sourceTopic,
        string $dlqTopic,
    ): void {
        $headers = $message->getHeaders();
        $body = $message->getBody();

        $headerEventType = $headers['event-type'] ?? null;
        $bodyEventType = $body['event_type'] ?? $body['event'] ?? null;
        $eventType = $headerEventType ?? $bodyEventType;
        if (! is_string($eventType) || $eventType === '') {
            Log::debug("Игнорируем событие {$sourceTopic}: отсутствует event-type");

            return;
        }

        if (! $allowedTypes->contains($eventType)) {
            Log::debug("Игнорируем событие {$sourceTopic}: {$eventType}");

            return;
        }

        $notificationType = NotificationEventType::tryFrom($eventType);
        if ($notificationType === null) {
            Log::debug("Игнорируем событие {$sourceTopic}: неизвестный тип {$eventType}");

            return;
        }

        $emailAddress = $body['email'] ?? null;
        if (! $emailAddress) {
            Log::warning("Kafka {$sourceTopic}: сообщение пропущено, отсутствует email", [
                'event-type' => $eventType,
            ]);

            return;
        }

        $messageId = KafkaMailMessageId::resolve($message, $sourceTopic, $eventType, $body);

        $existing = Notification::query()->where('message_id', $messageId)->first();
        if ($existing !== null && $existing->status === NotificationStatus::Sent->value) {
            Log::info("Kafka {$sourceTopic}: skip already sent", [
                'message_id' => $messageId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $data = OrderMailData::fromArray([...$body, 'event_type' => $eventType]);

        $result = $existing !== null
            ? $this->delivery->handle($data, $notificationType, null, $existing)
            : $this->delivery->handle($data, $notificationType, $messageId);

        if (! $result->isSent) {
            $this->sendToDlq($message, $result->notification, $result->lastError, $dlqTopic);
        }
    }

    private function sendToDlq(
        EnvelopeMessage $message,
        Notification $notification,
        string $errorMessage,
        string $dlqTopic,
    ): void {
        $notification->update([
            'status' => NotificationStatus::Failed->value,
            'error_message' => $errorMessage,
        ]);

        event(new KafkaEventMovedToDlq(
            $message,
            $errorMessage,
            (string) $notification->email,
            $dlqTopic,
        ));

        $notification->update([
            'moved_to_dlq' => true,
        ]);
    }
}
