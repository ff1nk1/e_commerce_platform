<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kafka\Handlers\OrderEventsHandler;
use App\Mail\MailableRegistry;
use App\Mail\OrderCancelledMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderPaidMail;
use App\Messaging\OrderNotification;
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

final class OrderEventsHandlerTest extends TestCase
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

    public function test_handler_sends_order_paid_mail(): void
    {
        Mail::fake();

        $body = $this->orderBody();
        resolve(OrderEventsHandler::class)->onInbound($this->message('order.paid', $body));

        Mail::assertSent(OrderPaidMail::class, function (OrderPaidMail $mail) use ($body): bool {
            return $mail->hasTo($body['email'])
                && ($mail->payload['order_id'] ?? null) === $body['order_id'];
        });

        $log = EmailLog::query()->where('email', $body['email'])->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('order.paid', $log->event_type);
        $this->assertSame('order.paid', $log->payload['event_type'] ?? null);
        $this->assertNotEmpty($log->message_id);
    }

    public function test_handler_sends_order_confirmed_mail(): void
    {
        Mail::fake();

        $body = $this->orderBody();
        resolve(OrderEventsHandler::class)->onInbound($this->message('order.confirmed', $body));

        Mail::assertSent(OrderConfirmedMail::class);
        $log = EmailLog::query()->where('status', 'sent')->first();
        $this->assertNotNull($log);
        $this->assertSame('order.confirmed', $log->event_type);
        $this->assertSame('order.confirmed', $log->payload['event_type'] ?? null);
    }

    public function test_handler_sends_order_cancelled_mail(): void
    {
        Mail::fake();

        $body = $this->orderBody(['reason' => 'insufficient_stock']);
        resolve(OrderEventsHandler::class)->onInbound($this->message('order.cancelled', $body));

        Mail::assertSent(OrderCancelledMail::class, function (OrderCancelledMail $mail): bool {
            return ($mail->payload['reason'] ?? null) === 'insufficient_stock';
        });

        $log = EmailLog::query()->where('email', $body['email'])->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
    }

    public function test_handler_uses_event_type_from_body_when_header_missing(): void
    {
        Mail::fake();

        $body = $this->orderBody(['event_type' => 'order.paid']);
        $message = $this->message('', $body, '42', []);

        resolve(OrderEventsHandler::class)->onInbound($message);

        Mail::assertSent(OrderPaidMail::class);
        $log = EmailLog::query()->where('email', $body['email'])->first();
        $this->assertNotNull($log);
        $this->assertSame('order.paid', $log->event_type);
    }

    public function test_handler_ignores_unknown_event_types(): void
    {
        Mail::fake();
        Kafka::fake();

        resolve(OrderEventsHandler::class)->onInbound($this->message('order.created', $this->orderBody()));

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count());
        Kafka::assertNothingPublished();
    }

    public function test_handler_skips_messages_without_email(): void
    {
        Mail::fake();
        Kafka::fake();

        $message = $this->message('order.paid', [
            'order_id' => 1,
            'name' => 'No Email',
        ], '1');

        resolve(OrderEventsHandler::class)->onInbound($message);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::query()->count());
        Kafka::assertNothingPublished();
    }

    public function test_handler_is_idempotent_for_already_sent_message_id(): void
    {
        Mail::fake();

        $body = $this->orderBody();
        $message = $this->message('order.paid', $body, '42', [
            'event-type' => 'order.paid',
            'laravel-kafka::message-id' => 'idempotent-order-1',
        ]);

        resolve(OrderEventsHandler::class)->onInbound($message);
        resolve(OrderEventsHandler::class)->onInbound($message);

        Mail::assertSent(OrderPaidMail::class, 1);
        $this->assertSame(1, EmailLog::query()->where('message_id', 'idempotent-order-1')->count());
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
            ->with('dlq-order@example.com')
            ->andReturn($pendingMailMock);

        /** @var MailDeliveryService&MockInterface $service */
        $service = Mockery::mock(MailDeliveryService::class, [new MailableRegistry])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('sleepInTest')->times($maxRetries - 1);
        $this->app->instance(MailDeliveryService::class, $service);

        $body = $this->orderBody(['email' => 'dlq-order@example.com']);
        $message = $this->message('order.paid', $body, '99');

        resolve(OrderEventsHandler::class)->onInbound($message);

        $log = EmailLog::query()->where('email', 'dlq-order@example.com')->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertTrue((bool) $log->moved_to_dlq);
        $this->assertSame($maxRetries, $log->retries);
        $this->assertSame('order.paid', $log->event_type);
        $this->assertSame('order.paid', $log->payload['event_type'] ?? null);
        $this->assertStringContainsString('SMTP permanently down', (string) $log->error_message);

        OutboxQueue::flush();
        Kafka::assertPublishedOn('order.events.dlq');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderBody(array $overrides = []): array
    {
        return array_merge([
            'order_id' => 42,
            'user_id' => 7,
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
            'status' => 'confirmed',
            'total_amount' => '50.00',
            'items' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function message(
        string $eventType,
        array $body,
        ?string $key = '42',
        ?array $headers = null,
    ): OrderNotification {
        $headers ??= $eventType === '' ? [] : ['event-type' => $eventType];
        $stringHeaders = [];
        foreach ($headers as $name => $value) {
            $stringHeaders[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return new OrderNotification(new InboundEnvelope(
            eventType: $eventType,
            messageId: $stringHeaders['laravel-kafka::message-id'] ?? 'test-'.$eventType.'-'.($key ?? 'none'),
            topic: 'order.events',
            key: $key,
            sagaId: null,
            payload: $body,
            headers: $stringHeaders,
        ));
    }
}
