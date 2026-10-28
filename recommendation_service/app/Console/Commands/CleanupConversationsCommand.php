<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete expired conversation contexts after grace period (messages cascade)')]
#[Signature('conversations:cleanup')]
final class CleanupConversationsCommand extends Command
{
    public function handle(): int
    {
        // API blocks access when expires_at <= now(); physical delete waits grace minutes
        // so in-flight LLM turns that passed findOwnedActive cannot hit FK violations.
        $cutoff = now()->subMinutes(Conversation::CLEANUP_GRACE_MINUTES);

        $deleted = Conversation::query()
            ->where('expires_at', '<=', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} expired conversation(s).");

        return self::SUCCESS;
    }
}
