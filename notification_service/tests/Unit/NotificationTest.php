<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\Notifications\OrderMailData;
use App\Enums\NotificationEventType;
use App\Mail\WelcomeMail;
use App\Models\Notification;
use App\Services\Notifications\ResendNotificationService;
use App\Services\Notifications\RetryingMailSender;
use App\Services\Notifications\SendNotificationService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resend_email_success(): void
    {
        Mail::fake();

        $notification = Notification::factory()->create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(ResendNotificationService::class)->handle($notification);

        $this->assertTrue($result->isSent);

        Mail::assertSent(WelcomeMail::class, function ($mail) use ($notification): bool {
            $hasCorrectTo = $mail->hasTo($notification->email);
            $hasCorrectPayload =
                isset($mail->payload['email']) && $mail->payload['email'] === $notification->email &&
                isset($mail->payload['name']) && $mail->payload['name'] === $notification->name;

            return $hasCorrectTo && $hasCorrectPayload;
        });

        $notification->refresh();
        $this->assertEquals('sent', $notification->status);
        $this->assertNull($notification->error_message);
        $this->assertNotNull($notification->time);
    }

    public function test_resend_email_failure_handles_exception(): void
    {
        Sleep::fake();

        $maxRetries = RetryingMailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP connection timeout'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('test@example.com')
            ->andReturn($pendingMailMock);

        $notification = Notification::factory()->create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(ResendNotificationService::class)->handle($notification);

        $this->assertFalse($result->isSent);

        $notification->refresh();
        $this->assertEquals('failed', $notification->status);
        $this->assertEquals($maxRetries, $notification->retries);
        $this->assertEquals('Ошибка отправки из админки: SMTP connection timeout', $notification->error_message);
    }

    public function test_resend_email_retries_then_succeeds(): void
    {
        Sleep::fake();

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $callCount = 0;
        $pendingMailMock->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function () use (&$callCount): true {
                $callCount++;
                throw_if($callCount === 1, \Exception::class, 'SMTP timeout');

                return true;
            });

        Mail::shouldReceive('to')
            ->twice()
            ->with('retry@example.com')
            ->andReturn($pendingMailMock);

        $notification = Notification::factory()->failed()->create([
            'email' => 'retry@example.com',
            'name' => 'Retry',
            'event_type' => 'user.registered',
            'payload' => [
                'email' => 'retry@example.com',
                'name' => 'Retry',
                'event_type' => 'user.registered',
            ],
        ]);

        $result = resolve(ResendNotificationService::class)->handle($notification);

        $this->assertTrue($result->isSent);
        $notification->refresh();
        $this->assertEquals('sent', $notification->status);
        $this->assertEquals(2, $notification->retries);
        $this->assertNull($notification->error_message);
    }

    public function test_welcome_mail_has_correct_data_and_subject(): void
    {
        $payload = [
            'email' => 'test@example.com',
            'name' => 'Иван Иванов',
        ];

        $mail = new WelcomeMail(OrderMailData::fromArray($payload));

        $this->assertEquals('Добро пожаловать на наш сайт!', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Иван Иванов');
        $mail->assertSeeInHtml('test@example.com');
    }

    public function test_it_sends_email_successfully_on_first_attempt(): void
    {
        Mail::fake();
        $logSpy = Log::spy();

        $result = resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray(['email' => 'test@example.com', 'name' => 'John']),
            NotificationEventType::UserRegistered,
        );

        $this->assertTrue($result->isSent);
        $this->assertEmpty($result->lastError);
        $this->assertEquals('sent', $result->notification->status);
        $this->assertEquals(1, $result->notification->retries);

        Mail::assertSent(WelcomeMail::class, function ($mail) {
            return $mail->hasTo('test@example.com');
        });

        $logSpy->shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($msg): bool {
                return str_contains($msg, '[KAFKA УСПЕХ]');
            });
    }

    public function test_it_retries_and_succeeds_on_second_attempt(): void
    {
        Sleep::fake();

        $pendingMailMock = Mockery::mock(PendingMail::class);

        $callCount = 0;
        $pendingMailMock->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function () use (&$callCount): true {
                $callCount++;
                throw_if($callCount === 1, \Exception::class, 'SMTP timeout');

                return true;
            });

        Mail::shouldReceive('to')
            ->twice()
            ->with('test@example.com')
            ->andReturn($pendingMailMock);

        $logSpy = Log::spy();

        $result = resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray(['email' => 'test@example.com', 'name' => 'John']),
            NotificationEventType::UserRegistered,
        );

        $this->assertTrue($result->isSent);
        $this->assertEquals(2, $result->notification->retries);
        $this->assertEquals('sent', $result->notification->status);

        $logSpy->shouldHaveReceived('warning')->once();
        $logSpy->shouldHaveReceived('info')->once();

        Sleep::assertSleptTimes(1);
    }

    public function test_it_fails_after_max_retries(): void
    {
        Sleep::fake();

        $maxRetries = RetryingMailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('Critical SMTP Error'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('test@example.com')
            ->andReturn($pendingMailMock);

        $logSpy = Log::spy();

        $result = resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray(['email' => 'test@example.com']),
            NotificationEventType::UserRegistered,
        );

        $this->assertFalse($result->isSent);
        $this->assertEquals('Critical SMTP Error', $result->lastError);
        $this->assertEquals($maxRetries, $result->notification->retries);
        $this->assertEquals('failed', $result->notification->status);

        $logSpy->shouldHaveReceived('warning')->times($maxRetries - 1);
        $logSpy->shouldNotHaveReceived('info');

        Sleep::assertSleptTimes($maxRetries - 1);
    }

    public function test_retry_backoff_sleeps_follow_exponential_sequence(): void
    {
        Sleep::fake();

        $maxRetries = RetryingMailSender::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP down'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('backoff@example.com')
            ->andReturn($pendingMailMock);

        resolve(SendNotificationService::class)->handle(
            OrderMailData::fromArray([
                'email' => 'backoff@example.com',
                'name' => 'Backoff',
            ]),
            NotificationEventType::UserRegistered,
        );

        Sleep::assertSequence([
            Sleep::for(1)->seconds(),
            Sleep::for(2)->seconds(),
        ]);
    }
}
