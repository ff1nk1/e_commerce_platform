<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailLog;

final class ResendNotificationMailService
{
    public function __construct(
        private MailSender $mailSender,
    ) {}

    public function handle(EmailLog $notification): bool
    {
        return $this->mailSender->resend($notification);
    }
}
