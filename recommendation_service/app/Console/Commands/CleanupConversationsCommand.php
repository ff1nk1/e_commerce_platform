<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use Illuminate\Console\Command;

class CleanupConversationsCommand extends Command
{
    protected $signature = 'conversations:cleanup';

    protected $description = 'Delete expired conversation contexts after grace period (messages cascade)';

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
