<?php

namespace App\Services\Chat;

use App\Models\Message;
use Illuminate\Support\Collection;

final class FilterExtractPrompt
{
    /**
     * @param  Collection<int, object{role: string, content: string}>|\Illuminate\Database\Eloquent\Collection<int, Message>  $history
     * @param  array<string, mixed>|null  $previousFilters
     */
    public static function build(string $messageText, $history, ?array $previousFilters): string
    {
        $parts = [];

        if ($previousFilters !== null && $previousFilters !== []) {
            $compact = $previousFilters;
            unset($compact['relaxed']);
            $parts[] = 'Previous structured filters (JSON): '.json_encode(
                $compact,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        if (! $history->isEmpty()) {
            $lines = [];
            foreach ($history as $row) {
                if ($row->role !== Message::ROLE_USER) {
                    continue;
                }
                $lines[] = 'user: '.(string) $row->content;
            }
            if ($lines !== []) {
                $parts[] = "Conversation so far (user turns):\n".implode("\n", $lines);
            }
        }

        $parts[] = "Current user message:\n".$messageText;

        return implode("\n\n", $parts);
    }
}
