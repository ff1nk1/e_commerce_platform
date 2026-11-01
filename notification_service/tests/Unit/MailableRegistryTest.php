<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Notifications\NotificationMailData;
use App\Mail\MailableRegistry;
use App\Mail\OrderPaidMail;
use App\Mail\WelcomeMail;
use InvalidArgumentException;
use Tests\TestCase;

final class MailableRegistryTest extends TestCase
{
    public function test_make_returns_welcome_mail_for_user_registered(): void
    {
        $mail = (new MailableRegistry)->make('user.registered', NotificationMailData::fromArray([
            'email' => 'a@example.com',
            'name' => 'A',
        ]));

        $this->assertInstanceOf(WelcomeMail::class, $mail);
    }

    public function test_make_returns_order_paid_mail(): void
    {
        $mail = (new MailableRegistry)->make('order.paid', NotificationMailData::fromArray([
            'email' => 'a@example.com',
            'order_id' => 1,
        ]));

        $this->assertInstanceOf(OrderPaidMail::class, $mail);
    }

    public function test_make_throws_on_unknown_event_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown notification event_type: order.created');

        (new MailableRegistry)->make('order.created', NotificationMailData::fromArray(['email' => 'a@example.com']));
    }

    public function test_make_throws_on_empty_event_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MailableRegistry)->make('', NotificationMailData::fromArray(['email' => 'a@example.com']));
    }
}
