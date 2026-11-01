<?php

declare(strict_types=1);

namespace App\DTO\Chat;

use App\Enums\MessageRole;

final readonly class ChatMessage
{
    public function __construct(
        public MessageRole $role,
        public string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(MessageRole::Assistant, $content);
    }

    public static function system(string $content): self
    {
        return new self(MessageRole::System, $content);
    }
}
