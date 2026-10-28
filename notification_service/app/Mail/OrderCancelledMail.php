<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OrderCancelledMail extends Mailable
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
        $orderId = $this->payload['order_id'] ?? '';

        return new Envelope(
            subject: "Заказ №{$orderId} отменён",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-cancelled',
        );
    }
}
