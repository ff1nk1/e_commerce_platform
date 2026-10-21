<?php

namespace Tests\Unit;

use App\Models\Conversation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ConversationTtlTest extends TestCase
{
    public function test_idle_cap_wins_when_shorter_than_hard(): void
    {
        $created = Carbon::parse('2026-01-01 12:00:00');
        $activity = Carbon::parse('2026-01-01 12:00:00');

        $expires = Conversation::computeExpiresAt($created, $activity);

        $this->assertTrue($expires->equalTo($activity->copy()->addMinutes(Conversation::IDLE_TTL_MINUTES)));
    }

    public function test_hard_cap_wins_near_end_of_life(): void
    {
        $created = Carbon::parse('2026-01-01 00:00:00');
        $activity = Carbon::parse('2026-01-01 23:50:00');

        $expires = Conversation::computeExpiresAt($created, $activity);

        $this->assertTrue($expires->equalTo($created->copy()->addHours(Conversation::HARD_TTL_HOURS)));
    }

    public function test_cleanup_grace_minutes_is_five(): void
    {
        $this->assertSame(5, Conversation::CLEANUP_GRACE_MINUTES);
    }
}
