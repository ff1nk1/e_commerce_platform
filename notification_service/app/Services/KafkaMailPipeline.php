<?php

namespace App\Services;

use App\Events\KafkaEventMovedToDlq;
use App\Models\EmailLog;
use App\Support\KafkaMailMessageId;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;

class KafkaMailPipeline
{
    public function __construct(
        private MailDeliveryService $delivery,
    ) {}

    /**
     * @param  list<string>  $allowedTypes
     */
    public function handle(
        ConsumerMessage $message,
        array $allowedTypes,
        string $sourceTopic,
        string $dlqTopic,
    ): void {
        $headers = $message->getHeaders() ?? [];
        $body = $message->getBody();

        if (! is_array($body)) {
            Log::warning("Kafka {$sourceTopic}: тело сообщения не массив", [
                'event-type' => $headers['event-type'] ?? null,
            ]);

            return;
        }

        $headerEventType = $headers['event-type'] ?? null;
        $bodyEventType = $body['event_type'] ?? null;
        $eventType = $headerEventType ?? $bodyEventType;
        if (! is_string($eventType) || $eventType === '') {
            Log::debug("Игнорируем событие {$sourceTopic}: отсутствует event-type");

            return;
        }

        if (! in_array($eventType, $allowedTypes, true)) {
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
        if ($existing !== null && $existing->status === 'sent') {
            Log::info("Kafka {$sourceTopic}: skip already sent", [
                'message_id' => $messageId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $payload = $body;
        $payload['event_type'] = $eventType;

        if ($existing !== null) {
            $result = $this->delivery->deliverExisting($existing, $payload, $eventType);
        } else {
            $result = $this->delivery->sendMailWithRetry($payload, $eventType, $messageId);
        }

        if (! $result['isSent']) {
            $this->sendToDlq($message, $result['emailLog'], $result['lastError'], $dlqTopic);
        }
    }

    private function sendToDlq(
        ConsumerMessage $message,
        EmailLog $emailLog,
        string $errorMessage,
        string $dlqTopic,
    ): void {
        $emailLog->update([
            'status' => 'failed',
            'moved_to_dlq' => true,
            'error_message' => $errorMessage,
        ]);

        event(new KafkaEventMovedToDlq(
            $message,
            $errorMessage,
            (string) $emailLog->email,
            $dlqTopic,
        ));
    }
}
