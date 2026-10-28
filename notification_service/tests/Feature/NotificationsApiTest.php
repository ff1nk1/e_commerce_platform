<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPaidMail;
use App\Models\EmailLog;
use App\Services\MailDeliveryService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class NotificationsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EmailLog::query()->truncate();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_paginated_notifications(): void
    {
        EmailLog::factory()->count(3)->create(['status' => 'sent']);

        $response = $this->getJson('/api/notifications?per_page=2');

        $response->assertOk()
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_status_email_and_event_type(): void
    {
        EmailLog::factory()->create([
            'email' => 'keep@example.com',
            'status' => 'failed',
            'event_type' => 'order.paid',
        ]);
        EmailLog::factory()->create([
            'email' => 'other@example.com',
            'status' => 'sent',
            'event_type' => 'user.registered',
        ]);

        $response = $this->getJson('/api/notifications?status=failed&email=keep@example.com&event_type=order.paid');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.email', 'keep@example.com')
            ->assertJsonPath('data.0.event_type', 'order.paid');
    }

    public function test_show_returns_email_log_from_mongo(): void
    {
        $log = EmailLog::factory()->failed('SMTP down')->create([
            'email' => 'a@example.com',
            'name' => 'A',
            'event_type' => 'order.paid',
            'payload' => ['order_id' => 1, 'email' => 'a@example.com', 'name' => 'A'],
        ]);

        $response = $this->getJson('/api/notifications/'.$log->id);

        $response->assertOk()
            ->assertJsonPath('email', $log->email)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('event_type', 'order.paid')
            ->assertJsonPath('error_message', 'SMTP down');
    }

    public function test_resend_failed_order_paid_sends_correct_mailable(): void
    {
        Mail::fake();

        $log = EmailLog::factory()->failed()->create([
            'event_type' => 'order.paid',
            'email' => 'buyer@example.com',
            'payload' => [
                'order_id' => 99,
                'email' => 'buyer@example.com',
                'name' => 'Buyer',
                'items' => [],
            ],
        ]);

        $response = $this->postJson('/api/notifications/'.$log->id.'/resend');

        $response->assertOk()
            ->assertJsonPath('data.status', 'sent');

        Mail::assertSent(OrderPaidMail::class, function (OrderPaidMail $mail): bool {
            return ($mail->payload['order_id'] ?? null) === 99;
        });
    }

    public function test_resend_rejects_non_failed_status(): void
    {
        Mail::fake();

        $log = EmailLog::factory()->sent()->create();

        $response = $this->postJson('/api/notifications/'.$log->id.'/resend');

        $response->assertUnprocessable();
        Mail::assertNothingSent();
    }

    public function test_resend_paid_and_confirmed_send_distinct_mailables(): void
    {
        Mail::fake();

        $sharedPayload = [
            'order_id' => 17,
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
            'items' => [],
        ];

        $paid = EmailLog::factory()->failed()->create([
            'event_type' => 'order.paid',
            'email' => 'buyer@example.com',
            'payload' => array_merge($sharedPayload, ['event_type' => 'order.paid']),
        ]);

        $confirmed = EmailLog::factory()->failed()->create([
            'event_type' => 'order.confirmed',
            'email' => 'buyer@example.com',
            'payload' => array_merge($sharedPayload, ['event_type' => 'order.confirmed']),
        ]);

        $this->postJson('/api/notifications/'.$paid->id.'/resend')->assertOk();
        $this->postJson('/api/notifications/'.$confirmed->id.'/resend')->assertOk();

        Mail::assertSent(OrderPaidMail::class, 1);
        Mail::assertSent(OrderConfirmedMail::class, 1);
    }

    public function test_resend_rejects_order_log_without_event_type(): void
    {
        Mail::fake();

        $log = EmailLog::factory()->failed()->create([
            'event_type' => null,
            'email' => 'buyer@example.com',
            'payload' => [
                'order_id' => 17,
                'email' => 'buyer@example.com',
                'name' => 'Buyer',
            ],
        ]);

        $response = $this->postJson('/api/notifications/'.$log->id.'/resend');

        $response->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'У записи нет event_type, переотправка невозможна.',
            ]);
        Mail::assertNothingSent();
    }

    public function test_resend_uses_event_type_from_payload_when_top_level_missing(): void
    {
        Mail::fake();

        $log = EmailLog::factory()->failed()->create([
            'event_type' => null,
            'email' => 'buyer@example.com',
            'payload' => [
                'order_id' => 42,
                'email' => 'buyer@example.com',
                'name' => 'Buyer',
                'event_type' => 'order.paid',
            ],
        ]);

        $this->postJson('/api/notifications/'.$log->id.'/resend')->assertOk();

        Mail::assertSent(OrderPaidMail::class);
    }

    public function test_resend_returns_400_when_smtp_fails(): void
    {
        $maxRetries = MailDeliveryService::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP Connection Rejected'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->andReturn($pendingMailMock);

        $log = EmailLog::factory()->failed()->create([
            'email' => 'fail@example.com',
            'event_type' => 'user.registered',
            'payload' => [
                'email' => 'fail@example.com',
                'name' => 'Fail',
                'event_type' => 'user.registered',
            ],
        ]);

        $response = $this->postJson('/api/notifications/'.$log->id.'/resend');

        $response->assertBadRequest()
            ->assertJsonFragment([
                'message' => 'Ошибка отправки из админки: SMTP Connection Rejected',
            ]);

        $this->assertEquals($maxRetries, $log->fresh()->retries);
    }
}
