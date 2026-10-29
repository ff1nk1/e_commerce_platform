<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kafka\Handlers\UserEventsHandler;
use App\Mail\MailableRegistry;
use App\Mail\WelcomeMail;
use App\Messaging\UserNotification;
use App\Models\EmailLog;
use App\Services\MailDeliveryService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Junges\Kafka\Facades\Kafka;
use Mockery;
use Mockery\MockInterface;
use Shop\Messaging\InboundEnvelope;
use Shop\Messaging\OutboxQueue;
use Tests\TestCase;

final class UserEventsHandlerTest extends TestCase
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

    public function test_handler_sends_welcome_mail_on_user_registered(): void
    {
        Mail::fake();

        $message = $this->message([
            'email' => 'new@example.com',
            'name' => 'New User',
            'user_id' => 7,
        ], 'user.registered');

        resolve(UserEventsHandler::class)->onInbound($message);

        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) {
            return $mail->hasTo('new@example.com');
        });

        $log = EmailLog::query()->where('email', 'new@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('user.registered', $log->event_type);
        $this->assertNotEmpty($log->message_id);
    }

    public function test_handler_ignores_unknown_event_types(): void
    {
        Mail::fake();
        Kafka::fake();

        resolve(UserEventsHandler::class)->onInbound($this->message([
            'email' => 'x@example.com',
            'name' => 'X',
        ], 'user.updated'));

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count());
        Kafka::assertNothingPublished();
    }

    public function test_handler_ignores_messages_without_event_type(): void
    {
        Mail::fake();
        Kafka::fake();

        $message = $this->message([
            'email' => 'x@example.com',
            'name' => 'X',
        ], '', '1', []);

        resolve(UserEventsHandler::class)->onInbound($message);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count());
        Kafka::assertNothingPublished();
    }

    public function test_handler_is_idempotent_for_already_sent_message_id(): void
    {
        Mail::fake();

        $message = $this->message([
            'email' => 'dup@example.com',
            'name' => 'Dup',
        ], 'user.registered', '7', [
            'event-type' => 'user.registered',
            'laravel-kafka::message-id' => 'idempotent-user-1',
        ]);

        resolve(UserEventsHandler::class)->onInbound($message);
        resolve(UserEventsHandler::class)->onInbound($message);

        Mail::assertSent(WelcomeMail::class, 1);
        $this->assertSame(1, EmailLog::query()->where('message_id', 'idempotent-user-1')->count());
    }

    public function test_handler_marks_failed_and_publishes_to_dlq_after_retries_exhausted(): void
    {
        Kafka::fake();

        $maxRetries = MailDeliveryService::MAX_RETRIES;
        $pendingMailMock = Mockery::mock(PendingMail::class);
        $pendingMailMock->shouldReceive('send')
            ->times($maxRetries)
            ->andThrow(new \Exception('SMTP permanently down'));

        Mail::shouldReceive('to')
            ->times($maxRetries)
            ->with('dlq@example.com')
            ->andReturn($pendingMailMock);

        /** @var MailDeliveryService&MockInterface $service */
        $service = Mockery::mock(MailDeliveryService::class, [new MailableRegistry])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepInTest')->times($maxRetries - 1);
        $this->app->instance(MailDeliveryService::class, $service);

        $handler = resolve(UserEventsHandler::class);
        $handler->onInbound($this->message([
            'email' => 'dlq@example.com',
            'name' => 'DLQ User',
            'user_id' => 42,
        ], 'user.registered', '42'));

        $log = EmailLog::query()->where('email', 'dlq@example.com')->first();
        $this->assertNotNull($log);
        $this->assertEquals('failed', $log->status);
        $this->assertTrue((bool) $log->moved_to_dlq);
        $this->assertEquals($maxRetries, $log->retries);
        $this->assertStringContainsString('SMTP permanently down', (string) $log->error_message);

        OutboxQueue::flush();
        Kafka::assertPublishedOn('user.events.dlq');
    }

    public function test_handler_skips_messages_without_email(): void
    {
        Kafka::fake();
        Mail::fake();

        $message = $this->message(
            ['name' => 'No Email'],
            'user.registered',
            '1',
        );

        resolve(UserEventsHandler::class)->onInbound($message);

        $this->assertSame(0, EmailLog::query()->count());
        Kafka::assertNothingPublished();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>|null  $headers
     */
    private function message(
        array $body,
        string $eventType,
        ?string $key = '7',
        ?array $headers = null,
    ): UserNotification {
        $headers ??= $eventType === '' ? [] : ['event-type' => $eventType];
        $stringHeaders = [];
        foreach ($headers as $name => $value) {
            $stringHeaders[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return new UserNotification(new InboundEnvelope(
            eventType: $eventType,
            messageId: $stringHeaders['laravel-kafka::message-id'] ?? 'test-'.$eventType.'-'.($key ?? 'none'),
            topic: 'user.events',
            key: $key,
            sagaId: null,
            payload: $body,
            headers: $stringHeaders,
        ));
    }
}
