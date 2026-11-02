<?php

declare(strict_types=1);

namespace App\Mail;

use App\DTO\Notifications\OrderMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OrderPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(OrderMailData $mailData)
    {
        $this->payload = $mailData->toViewArray();
    }

    public function envelope(): Envelope
    {
        $orderId = $this->payload['order_id'] ?? '';

        return new Envelope(
            subject: "Оплата заказа №{$orderId} получена",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-paid',
        );
    }
}
