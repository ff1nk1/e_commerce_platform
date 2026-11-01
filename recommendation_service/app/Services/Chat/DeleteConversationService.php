<?php

declare(strict_types=1);

namespace App\Services\Chat;

final class DeleteConversationService
{
    public function __construct(
        private readonly OwnedConversationFinder $conversations,
    ) {}

    public function handle(string $userId, string $conversationId): void
    {
        $this->conversations->find($userId, $conversationId)->delete();
    }
}
