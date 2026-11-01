<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Conversation;

final class GetConversationService
{
    public function __construct(
        private readonly OwnedConversationFinder $conversations,
    ) {}

    public function handle(string $userId, string $conversationId): Conversation
    {
        return $this->conversations->find($userId, $conversationId);
    }
}
