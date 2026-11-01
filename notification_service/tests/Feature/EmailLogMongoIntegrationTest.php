<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MailableRegistry;
use App\Models\EmailLog;
use App\Services\Mail\MailSender;
use App\Services\Mail\ResendNotificationMailService;
use App\Services\Mail\SendNotificationMailService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class EmailLogMongoIntegrationTest extends TestCase
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

    public function test_it_persists_initial_pending_state_and_updates_to_sent_in_mongodb(): void
    {
        Mail::fake();

        $result = resolve(SendNotificationMailService::class)->handle([
            'email' => 'mongo_user@example.com',
            'name' => 'Mongo Test User',
        ], 'user.registered');

        $emailLog = $result->emailLog;

        $this->assertNotNull($emailLog->_id);
        $this->assertEquals('mongo_user@example.com', $emailLog->email);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $emailLog->_id,
            'email' => 'mongo_user@example.com',
            'status' => 'sent',
            'retries' => 1,
            'moved_to_dlq' => false,
            'error_message' => null,
        ], 'mongodb');

        $emailLog->refresh();
        $this->assertNotNull($emailLog->time);
    }

    public function test_it_updates_status_to_failed_and_stores_error_message_in_mongodb(): void
    {
        $maxRetries = MailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP Connection Rejected'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('fail_user@example.com')
            ->andReturn($pendingMailMock);

        $logInMongo = EmailLog::factory()->create([
            'email' => 'fail_user@example.com',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(ResendNotificationMailService::class)->handle($logInMongo);

        $this->assertFalse($result);

        $logInMongo->refresh();

        $this->assertEquals('failed', $logInMongo->status);
        $this->assertEquals('Ошибка отправки из админки: SMTP Connection Rejected', $logInMongo->error_message);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $logInMongo->_id,
            'status' => 'failed',
            'error_message' => 'Ошибка отправки из админки: SMTP Connection Rejected',
        ], 'mongodb');
    }

    public function test_it_increments_retries_count_in_mongodb_on_failures(): void
    {
        $maxRetries = MailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('Mongo Retry Test Failure'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('retry_mongo@example.com')
            ->andReturn($pendingMailMock);

        /** @var MailSender&MockInterface $sender */
        $sender = Mockery::mock(MailSender::class, [new MailableRegistry])->makePartial();
        $sender->shouldAllowMockingProtectedMethods();
        $sender->shouldReceive('sleepInTest')->times($maxRetries - 1);

        $result = (new SendNotificationMailService($sender))->handle([
            'email' => 'retry_mongo@example.com',
            'name' => 'Retry User',
        ], 'user.registered');

        $emailLog = $result->emailLog;
        $emailLog->refresh();

        $this->assertEquals($maxRetries, $emailLog->retries);
        $this->assertEquals('failed', $emailLog->status);

        $this->assertDatabaseHas('email_logs', [
            '_id' => $emailLog->_id,
            'retries' => $maxRetries,
            'status' => 'failed',
        ], 'mongodb');
    }
}
