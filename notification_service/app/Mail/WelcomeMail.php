<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Добро пожаловать на наш сайт!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome', // Указываем шаблон Blade
        );
    }
}
