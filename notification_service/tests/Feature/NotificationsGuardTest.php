<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class NotificationsGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::query()->truncate();
    }

    public function test_request_without_role_header_is_forbidden(): void
    {
        Notification::factory()->count(2)->create();

        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_customer_role_is_forbidden(): void
    {
        $this->withHeader('X-User-Role', 'customer')
            ->getJson('/api/notifications')
            ->assertForbidden();
    }

    public function test_analyst_can_read_notifications(): void
    {
        Notification::factory()->count(2)->create(['status' => 'sent']);

        $this->withHeader('X-User-Role', 'analyst')
            ->getJson('/api/notifications')
            ->assertOk();
    }

    public function test_analyst_cannot_resend_notification(): void
    {
        $log = Notification::factory()->failed()->create(['event_type' => 'order.paid']);

        $this->withHeader('X-User-Role', 'analyst')
            ->postJson('/api/notifications/'.$log->id.'/resend')
            ->assertForbidden();
    }

    public function test_admin_can_resend_notification(): void
    {
        $log = Notification::factory()->failed()->create([
            'event_type' => 'user.registered',
            'email' => 'admin-resend@example.com',
            'payload' => [
                'email' => 'admin-resend@example.com',
                'name' => 'Admin',
                'event_type' => 'user.registered',
            ],
        ]);

        Mail::fake();

        $this->withHeader('X-User-Role', 'admin')
            ->postJson('/api/notifications/'.$log->id.'/resend')
            ->assertOk();
    }
}
