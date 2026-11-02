<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\Notifications\DeliveryResult;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

final class RetryingMailSender
{
    public const MAX_RETRIES = 3;

    public const INITIAL_BACKOFF_SEC = 1;

    /**
     * Deliver a mailable with exponential-backoff retries, persisting attempts.
     *
     * @param  callable(): Mailable  $mailableFactory
     */
    public function attemptSend(Notification $notification, string $emailAddress, callable $mailableFactory): DeliveryResult
    {
        $attempt = 0;
        $isSent = false;
        $lastError = '';

        while ($attempt < self::MAX_RETRIES && ! $isSent) {
            $attempt++;

            try {
                Mail::to($emailAddress)->send($mailableFactory());

                $notification->update([
                    'status' => NotificationStatus::Sent->value,
                    'retries' => $attempt,
                    'moved_to_dlq' => false,
                    'error_message' => null,
                    'time' => now()->getTimestamp(),
                ]);

                $isSent = true;
                Log::info("[KAFKA УСПЕХ] Письмо отправлено на {$emailAddress} с попытки {$attempt}");
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $notification->update(['retries' => $attempt]);

                if ($attempt < self::MAX_RETRIES) {
                    $sleepTime = self::INITIAL_BACKOFF_SEC * (2 ** ($attempt - 1));

                    Log::warning("SMTP Сбой (Попытка {$attempt}/".self::MAX_RETRIES."). Ждем {$sleepTime} сек...", [
                        'email' => $emailAddress,
                        'error' => $lastError,
                    ]);

                    Sleep::for($sleepTime)->seconds();
                }
            }
        }

        if (! $isSent) {
            $notification->update([
                'status' => NotificationStatus::Failed->value,
                'error_message' => $lastError,
            ]);
        }

        return $isSent
            ? DeliveryResult::sent($notification)
            : DeliveryResult::failed($notification, $lastError);
    }
}
