<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailableRegistry;
use App\Models\EmailLog;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

class MailDeliveryService
{
    public const MAX_RETRIES = 3;

    public const INITIAL_BACKOFF_SEC = 1;

    public function __construct(
        private MailableRegistry $mailableRegistry,
    ) {}

    /**
     * Admin resend on an existing EmailLog.
     */
    public function resend(EmailLog $notification): bool
    {
        $eventType = $this->resolveEventTypeForResend($notification);
        throw_if($eventType === null, InvalidArgumentException::class, 'У записи нет event_type: переотправка невозможна (order.paid и order.confirmed неразличимы по payload).');

        $logEmail = $notification->email;
        $payload = is_array($notification->payload) ? $notification->payload : [];
        $payloadEmail = $logEmail;
        $payload['email'] = $payloadEmail;
        $payload['event_type'] = $eventType;
        if ($notification->name !== null && $notification->name !== '') {
            $payload['name'] = $notification->name;
        }

        $notification->update([
            'status' => 'pending',
            'error_message' => null,
            'moved_to_dlq' => false,
            'retries' => 0,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        $result = $this->attemptSendWithRetry(
            $notification,
            (string) $logEmail,
            fn (): Mailable => $this->mailableRegistry->make($eventType, $payload),
        );

        if (! $result['isSent']) {
            $notification->update([
                'status' => 'failed',
                'error_message' => 'Ошибка отправки из админки: '.$result['lastError'],
            ]);
        }

        return $result['isSent'];
    }

    /**
     * @return string|null null when order mail cannot be uniquely typed
     */
    public function resolveEventTypeForResend(EmailLog $notification): ?string
    {
        $storedEventType = $notification->event_type;
        $hasStoredEventType = is_string($storedEventType) && $storedEventType !== '';
        if ($hasStoredEventType) {
            return $storedEventType;
        }

        $payload = is_array($notification->payload) ? $notification->payload : [];
        $eventTypeFromPayload = $payload['event_type'] ?? null;
        $hasPayloadEventType = is_string($eventTypeFromPayload) && $eventTypeFromPayload !== '';
        if ($hasPayloadEventType) {
            return $eventTypeFromPayload;
        }

        $looksLikeOrderMail = isset($payload['order_id']);
        if ($looksLikeOrderMail) {
            // order.paid vs order.confirmed cannot be told apart from payload alone.
            return null;
        }

        return 'user.registered';
    }

    /**
     * Send mail with retries, creating a new EmailLog (non-idempotent path / tests).
     *
     * @param  array<string, mixed>  $body
     * @return array{isSent: bool, emailLog: EmailLog, lastError: string}
     */
    public function sendMailWithRetry(array $body, string $eventType, ?string $messageId = null): array
    {
        $emailAddress = $body['email'];

        $payload = $body;
        $payload['event_type'] = $eventType;

        $attributes = [
            'name' => $body['name'] ?? null,
            'email' => $emailAddress,
            'status' => 'pending',
            'retries' => 0,
            'moved_to_dlq' => false,
            'payload' => $payload,
            'event_type' => $eventType,
        ];

        if ($messageId !== null && $messageId !== '') {
            $attributes['message_id'] = $messageId;
        }

        $emailLog = EmailLog::query()->create($attributes);

        $result = $this->attemptSendWithRetry(
            $emailLog,
            (string) $emailAddress,
            fn (): Mailable => $this->mailableRegistry->make($eventType, $payload),
        );

        return [
            'isSent' => $result['isSent'],
            'emailLog' => $emailLog->fresh() ?? $emailLog,
            'lastError' => $result['lastError'],
        ];
    }

    /**
     * Deliver using an existing EmailLog (idempotent Kafka reuse path).
     *
     * @param  array<string, mixed>  $payload
     * @return array{isSent: bool, emailLog: EmailLog, lastError: string}
     */
    public function deliverExisting(EmailLog $emailLog, array $payload, string $eventType): array
    {
        $logEmail = (string) ($emailLog->email ?? '');
        $payloadEmail = (string) ($payload['email'] ?? '');
        $emailAddress = $logEmail !== '' ? $logEmail : $payloadEmail;

        $payload['event_type'] = $eventType;

        $emailLog->update([
            'status' => 'pending',
            'error_message' => null,
            'moved_to_dlq' => false,
            'retries' => 0,
            'event_type' => $eventType,
            'payload' => $payload,
            'name' => $payload['name'] ?? $emailLog->name,
            'email' => $emailAddress,
        ]);

        $result = $this->attemptSendWithRetry(
            $emailLog,
            $emailAddress,
            fn (): Mailable => $this->mailableRegistry->make($eventType, $payload),
        );

        return [
            'isSent' => $result['isSent'],
            'emailLog' => $emailLog->fresh() ?? $emailLog,
            'lastError' => $result['lastError'],
        ];
    }

    /**
     * @param  callable(): Mailable  $mailableFactory
     * @return array{isSent: bool, lastError: string}
     */
    public function attemptSendWithRetry(EmailLog $emailLog, string $emailAddress, callable $mailableFactory): array
    {
        $attempt = 0;
        $isSent = false;
        $lastError = '';

        while ($attempt < self::MAX_RETRIES && ! $isSent) {
            $attempt++;

            try {
                Mail::to($emailAddress)->send($mailableFactory());

                $emailLog->update([
                    'status' => 'sent',
                    'retries' => $attempt,
                    'moved_to_dlq' => false,
                    'error_message' => null,
                    'time' => now()->getTimestamp(),
                ]);

                $isSent = true;
                Log::info("[KAFKA УСПЕХ] Письмо отправлено на {$emailAddress} с попытки {$attempt}");
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $emailLog->update(['retries' => $attempt]);

                if ($attempt < self::MAX_RETRIES) {
                    $sleepTime = self::INITIAL_BACKOFF_SEC * (2 ** ($attempt - 1));

                    Log::warning("SMTP Сбой (Попытка {$attempt}/".self::MAX_RETRIES."). Ждем {$sleepTime} сек...", [
                        'email' => $emailAddress,
                        'error' => $lastError,
                    ]);

                    $this->sleepInTest($sleepTime);
                }
            }
        }

        if (! $isSent) {
            $emailLog->update([
                'status' => 'failed',
                'error_message' => $lastError,
            ]);
        }

        return [
            'isSent' => $isSent,
            'lastError' => $lastError,
        ];
    }

    /**
     * Extracted for unit-test overrides (skip real sleep under PHPUnit).
     */
    protected function sleepInTest(int $seconds): void
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') || app()->runningUnitTests()) {
            return;
        }

        Sleep::sleep($seconds);
    }
}
