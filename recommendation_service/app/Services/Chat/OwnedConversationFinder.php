<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;

final class OwnedConversationFinder
{
    public function resolve(string $userId, ?string $conversationId): Conversation
    {
        if ($conversationId === null || $conversationId === '') {
            $now = Date::now();

            return Conversation::query()->create([
                'user_id' => $userId,
                'last_activity_at' => $now,
                'expires_at' => Conversation::computeExpiresAt($now, $now),
            ]);
        }

        return $this->find($userId, $conversationId);
    }

    public function find(string $userId, string $conversationId): Conversation
    {
        $conversation = Conversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if ($conversation === null) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversationId]);
        }

        return $conversation;
    }
}
