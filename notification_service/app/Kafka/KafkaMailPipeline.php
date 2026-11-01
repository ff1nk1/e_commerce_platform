<?php

declare(strict_types=1);

namespace App\Kafka;

use App\Enums\EmailStatus;
use App\Enums\NotificationEventType;
use App\Events\KafkaEventMovedToDlq;
use App\Models\EmailLog;
use App\Services\Mail\SendNotificationMailService;
use App\Support\KafkaMailMessageId;
use Illuminate\Support\Facades\Log;
use Shop\Messaging\EnvelopeMessage;

final class KafkaMailPipeline
{
    public function __construct(
        private SendNotificationMailService $delivery,
    ) {}

    /**
     * @param  list<NotificationEventType>  $allowedTypes
     */
    public function handle(
        EnvelopeMessage $message,
        array $allowedTypes,
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

        if (! $this->eventTypeIsAllowed($eventType, $allowedTypes)) {
            Log::debug("Игнорируем событие {$sourceTopic}: {$eventType}");

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

        $existing = EmailLog::query()->where('message_id', $messageId)->first();
        if ($existing !== null && $existing->status === EmailStatus::Sent->value) {
            Log::info("Kafka {$sourceTopic}: skip already sent", [
                'message_id' => $messageId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $payload = $body;
        $payload['event_type'] = $eventType;

        if ($existing !== null) {
            $result = $this->delivery->handle($payload, $eventType, null, $existing);
        } else {
            $result = $this->delivery->handle($payload, $eventType, $messageId);
        }

        if (! $result->isSent) {
            $this->sendToDlq($message, $result->emailLog, $result->lastError, $dlqTopic);
        }
    }

    /**
     * @param  list<NotificationEventType>  $allowedTypes
     */
    private function eventTypeIsAllowed(string $eventType, array $allowedTypes): bool
    {
        foreach ($allowedTypes as $allowedType) {
            if ($allowedType->value === $eventType) {
                return true;
            }
        }

        return false;
    }

    private function sendToDlq(
        EnvelopeMessage $message,
        EmailLog $emailLog,
        string $errorMessage,
        string $dlqTopic,
    ): void {
        $emailLog->update([
            'status' => EmailStatus::Failed->value,
            'error_message' => $errorMessage,
        ]);

        event(new KafkaEventMovedToDlq(
            $message,
            $errorMessage,
            (string) $emailLog->email,
            $dlqTopic,
        ));

        $emailLog->update([
            'moved_to_dlq' => true,
        ]);
    }
}
