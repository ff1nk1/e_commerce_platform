<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTO\Notifications\OrderMailData;
use App\Enums\NotificationEventType;
use App\Models\Notification;
use App\Services\Notifications\ResendNotificationService;
use App\Services\Notifications\RetryingMailSender;
use App\Services\Notifications\SendNotificationService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

final class NotificationMongoIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::query()->truncate();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_persists_initial_pending_state_and_updates_to_sent_in_mongodb(): void
    {
        Mail::fake();

        $result = resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray([
                'email' => 'mongo_user@example.com',
                'name' => 'Mongo Test User',
            ]),
            NotificationEventType::UserRegistered,
        );

        $notification = $result->notification;

        $this->assertNotNull($notification->_id);
        $this->assertEquals('mongo_user@example.com', $notification->email);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $notification->_id,
            'email' => 'mongo_user@example.com',
            'status' => 'sent',
            'retries' => 1,
            'moved_to_dlq' => false,
            'error_message' => null,
        ], 'mongodb');

        $notification->refresh();
        $this->assertNotNull($notification->time);
    }

    public function test_it_updates_status_to_failed_and_stores_error_message_in_mongodb(): void
    {
        Sleep::fake();

        $maxRetries = RetryingMailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP Connection Rejected'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('fail_user@example.com')
            ->andReturn($pendingMailMock);

        $notification = Notification::factory()->create([
            'email' => 'fail_user@example.com',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(ResendNotificationService::class)->handle($notification);

        $this->assertFalse($result->isSent);

        $notification->refresh();

        $this->assertEquals('failed', $notification->status);
        $this->assertEquals('Ошибка отправки из админки: SMTP Connection Rejected', $notification->error_message);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $notification->_id,
            'status' => 'failed',
            'error_message' => 'Ошибка отправки из админки: SMTP Connection Rejected',
        ], 'mongodb');
    }

    public function test_it_increments_retries_count_in_mongodb_on_failures(): void
    {
        Sleep::fake();

        $maxRetries = RetryingMailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('Mongo Retry Test Failure'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('retry_mongo@example.com')
            ->andReturn($pendingMailMock);

        $result = resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray([
                'email' => 'retry_mongo@example.com',
                'name' => 'Retry User',
            ]),
            NotificationEventType::UserRegistered,
        );

        $notification = $result->notification;
        $notification->refresh();

        $this->assertEquals($maxRetries, $notification->retries);
        $this->assertEquals('failed', $notification->status);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $notification->_id,
            'retries' => $maxRetries,
            'status' => 'failed',
        ], 'mongodb');
    }
}
