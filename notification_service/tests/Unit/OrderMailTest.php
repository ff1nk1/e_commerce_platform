<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPaidMail;
use Tests\TestCase;

final class OrderMailTest extends TestCase
{
    public function test_order_paid_mail_subject_and_body(): void
    {
        $mail = new OrderPaidMail([
            'order_id' => 10,
            'email' => 'a@example.com',
            'name' => 'Анна',
            'total_amount' => '99.00',
        ]);

        $this->assertSame('Оплата заказа №10 получена', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Анна');
        $mail->assertSeeInHtml('№10');
        $mail->assertSeeInHtml('99.00');
    }

    public function test_order_confirmed_mail_subject_and_body(): void
    {
        $mail = new OrderConfirmedMail([
            'order_id' => 11,
            'email' => 'b@example.com',
            'name' => 'Борис',
            'total_amount' => '20.00',
        ]);

        $this->assertSame('Заказ №11 подтверждён', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Борис');
        $mail->assertSeeInHtml('подтверждён');
    }

    public function test_order_cancelled_mail_shows_insufficient_stock_reason(): void
    {
        $mail = new OrderCancelledMail([
            'order_id' => 12,
            'email' => 'c@example.com',
            'name' => 'Виктор',
            'reason' => 'insufficient_stock',
            'total_amount' => '15.00',
        ]);

        $this->assertSame('Заказ №12 отменён', $mail->envelope()->subject);
        $mail->assertSeeInHtml('нет в наличии');
    }

    public function test_order_cancelled_mail_shows_user_cancelled_reason(): void
    {
        $mail = new OrderCancelledMail([
            'order_id' => 13,
            'email' => 'd@example.com',
            'name' => 'Дима',
            'reason' => 'user_cancelled',
        ]);

        $mail->assertSeeInHtml('по вашему запросу');
    }
}
