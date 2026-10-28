<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Services\MailDeliveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Requires Mailpit (compose SMTP :1025, API :8025) and MongoDB.
 * Defaults to Docker DNS hostname; override with MAILPIT_HOST for host-side runs.
 */
final class WelcomeMailMailhogTest extends TestCase
{
    private string $mailpitHost;

    private string $mailpitApiBase;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('MAILPIT_HOST');
        if (! is_string($host) || $host === '') {
            $legacy = getenv('MAILHOG_HOST');
            $host = is_string($legacy) ? $legacy : '';
        }
        $this->mailpitHost = $host !== '' ? $host : 'notification_service_mailpit';
        $this->mailpitApiBase = 'http://'.$this->mailpitHost.':8025';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $this->mailpitHost,
            'mail.mailers.smtp.port' => 1025,
            'mail.mailers.smtp.encryption' => null,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);

        EmailLog::query()->truncate();

        if (! $this->mailpitIsReachable()) {
            $this->markTestSkipped('Mailpit is not running at '.$this->mailpitApiBase.'.');
        }

        Http::delete($this->mailpitApiBase.'/api/v1/messages');
    }

    public function test_welcome_mail_reaches_mailhog_and_persists_sent_in_mongo(): void
    {
        $email = 'mailhog_'.uniqid().'@example.com';
        $payload = [
            'email' => $email,
            'name' => 'Mailhog User',
        ];

        $result = resolve(MailDeliveryService::class)->sendMailWithRetry($payload, 'user.registered');

        $this->assertTrue($result['isSent']);
        $result['emailLog']->refresh();
        $this->assertEquals('sent', $result['emailLog']->status);

        $this->assertDatabaseHas('email_logs', [
            'email' => $email,
            'status' => 'sent',
            'moved_to_dlq' => false,
        ], 'mongodb');

        $inbox = Http::get($this->mailpitApiBase.'/api/v1/messages')->json();
        $this->assertGreaterThan(0, is_array($inbox) ? ($inbox['total'] ?? 0) : 0);

        /** @var array<int, array<string, mixed>> $messages */
        $messages = is_array($inbox) && is_array($inbox['messages'] ?? null) ? $inbox['messages'] : [];

        $matched = collect($messages)->first(function (array $item) use ($email): bool {
            foreach ($item['To'] ?? [] as $to) {
                $addr = (string) ($to['Address'] ?? '');
                if (strcasecmp($addr, $email) === 0) {
                    return true;
                }
            }

            return str_contains((string) json_encode($item), $email);
        });

        $this->assertNotNull($matched, 'Expected message for '.$email.' in Mailpit');

        // Mailpit decodes the subject. iconv_mime_decode is for MailHog's raw
        // =?UTF-8?B?...?= header and corrupts an already decoded UTF-8 string.
        $this->assertStringContainsString('Добро пожаловать', (string) ($matched['Subject'] ?? ''));
    }

    private function mailpitIsReachable(): bool
    {
        try {
            $response = Http::timeout(1)->get($this->mailpitApiBase.'/api/v1/messages');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
