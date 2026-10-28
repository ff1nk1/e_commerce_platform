<?php

declare(strict_types=1);

namespace App\Services\Llm\DTOs;

final readonly class ChatMessage
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public function __construct(
        public string $role,
        public string $content,
    ) {}

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }
}
