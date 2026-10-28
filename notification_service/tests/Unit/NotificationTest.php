<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\MailableRegistry;
use App\Mail\WelcomeMail;
use App\Models\EmailLog;
use App\Services\MailDeliveryService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
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

        $notification = EmailLog::factory()->create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(MailDeliveryService::class)->resend($notification);

        $this->assertTrue($result);

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
        $maxRetries = MailDeliveryService::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP connection timeout'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('test@example.com')
            ->andReturn($pendingMailMock);

        $notification = EmailLog::factory()->create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'status' => 'failed',
            'event_type' => 'user.registered',
        ]);

        $result = resolve(MailDeliveryService::class)->resend($notification);

        $this->assertFalse($result);

        $notification->refresh();
        $this->assertEquals('failed', $notification->status);
        $this->assertEquals($maxRetries, $notification->retries);
        $this->assertEquals('Ошибка отправки из админки: SMTP connection timeout', $notification->error_message);
    }

    public function test_resend_email_retries_then_succeeds(): void
    {
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

        $notification = EmailLog::factory()->failed()->create([
            'email' => 'retry@example.com',
            'name' => 'Retry',
            'event_type' => 'user.registered',
            'payload' => [
                'email' => 'retry@example.com',
                'name' => 'Retry',
                'event_type' => 'user.registered',
            ],
        ]);

        $result = resolve(MailDeliveryService::class)->resend($notification);

        $this->assertTrue($result);
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

        $mail = new WelcomeMail($payload);

        $this->assertEquals('Добро пожаловать на наш сайт!', $mail->envelope()->subject);
        $mail->assertSeeInHtml('Иван Иванов');
        $mail->assertSeeInHtml('test@example.com');
    }

    public function test_it_sends_email_successfully_on_first_attempt(): void
    {
        Mail::fake();
        $logSpy = Log::spy();

        $result = resolve(MailDeliveryService::class)->sendMailWithRetry(
            ['email' => 'test@example.com', 'name' => 'John'],
            'user.registered',
        );

        $this->assertTrue($result['isSent']);
        $this->assertEmpty($result['lastError']);
        $this->assertEquals('sent', $result['emailLog']->status);
        $this->assertEquals(1, $result['emailLog']->retries);

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

        /** @var MailDeliveryService&MockInterface $service */
        $service = Mockery::mock(MailDeliveryService::class, [new MailableRegistry])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepInTest')
            ->once()
            ->with(Mockery::type('numeric'));

        $result = $service->sendMailWithRetry(
            ['email' => 'test@example.com', 'name' => 'John'],
            'user.registered',
        );

        $this->assertTrue($result['isSent']);
        $this->assertEquals(2, $result['emailLog']->retries);
        $this->assertEquals('sent', $result['emailLog']->status);

        $logSpy->shouldHaveReceived('warning')->once();
        $logSpy->shouldHaveReceived('info')->once();
    }

    public function test_it_fails_after_max_retries(): void
    {
        $maxRetries = MailDeliveryService::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('Critical SMTP Error'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('test@example.com')
            ->andReturn($pendingMailMock);

        $logSpy = Log::spy();

        /** @var MailDeliveryService&MockInterface $service */
        $service = Mockery::mock(MailDeliveryService::class, [new MailableRegistry])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepInTest')
            ->times($maxRetries - 1);

        $result = $service->sendMailWithRetry(
            ['email' => 'test@example.com'],
            'user.registered',
        );

        $this->assertFalse($result['isSent']);
        $this->assertEquals('Critical SMTP Error', $result['lastError']);
        $this->assertEquals($maxRetries, $result['emailLog']->retries);
        $this->assertEquals('failed', $result['emailLog']->status);

        $logSpy->shouldHaveReceived('warning')->times($maxRetries - 1);
        $logSpy->shouldNotHaveReceived('info');
    }

    public function test_retry_backoff_sleeps_follow_exponential_sequence(): void
    {
        $maxRetries = MailDeliveryService::MAX_RETRIES;

        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP down'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('backoff@example.com')
            ->andReturn($pendingMailMock);

        $sleeps = [];

        /** @var MailDeliveryService&MockInterface $service */
        $service = Mockery::mock(MailDeliveryService::class, [new MailableRegistry])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepInTest')
            ->times($maxRetries - 1)
            ->andReturnUsing(function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            });

        $service->sendMailWithRetry([
            'email' => 'backoff@example.com',
            'name' => 'Backoff',
        ], 'user.registered');

        $this->assertSame([1, 2], $sleeps);
    }
}
