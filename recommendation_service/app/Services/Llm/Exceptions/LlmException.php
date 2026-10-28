<?php

declare(strict_types=1);

namespace App\Services\Llm\Exceptions;

use RuntimeException;
use Throwable;

final class LlmException extends RuntimeException
{
    public const KIND_HTTP = 'http';

    public const KIND_PARSE = 'parse';

    public const KIND_CONFIGURATION = 'configuration';

    public const KIND_CONNECTION = 'connection';

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly string $kind = self::KIND_HTTP,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configuration(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, self::KIND_CONFIGURATION, $previous);
    }

    public static function parse(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, self::KIND_PARSE, $previous);
    }

    public static function connection(string $message, ?int $statusCode = null, ?Throwable $previous = null): self
    {
        return new self($message, $statusCode, self::KIND_CONNECTION, $previous);
    }

    public static function http(string $message, int $statusCode, ?Throwable $previous = null): self
    {
        return new self($message, $statusCode, self::KIND_HTTP, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function userMessage(): string
    {
        if ($this->kind === self::KIND_CONFIGURATION) {
            return 'Сервис рекомендаций временно недоступен.';
        }

        if ($this->kind === self::KIND_PARSE) {
            return 'Не удалось обработать ответ ИИ. Попробуйте переформулировать запрос.';
        }

        if ($this->kind === self::KIND_CONNECTION) {
            if ($this->statusCode === 504) {
                return 'ИИ слишком долго отвечает. Попробуйте позже.';
            }

            return 'Не удалось связаться с сервисом ИИ. Попробуйте ещё раз.';
        }

        return match ($this->statusCode) {
            503 => 'Сервис ИИ сейчас перегружен. Попробуйте чуть позже.',
            429 => 'Слишком много запросов к ИИ. Подождите минуту и попробуйте снова.',
            504 => 'ИИ слишком долго отвечает. Попробуйте позже.',
            500, 502 => 'Не удалось связаться с сервисом ИИ. Попробуйте ещё раз.',
            400 => 'Не удалось обработать ответ ИИ. Попробуйте переформулировать запрос.',
            401, 403 => 'Сервис рекомендаций временно недоступен.',
            default => 'Не удалось получить ответ от ИИ. Попробуйте ещё раз.',
        };
    }

    public function publicHttpStatus(): int
    {
        if ($this->kind === self::KIND_CONFIGURATION) {
            return 503;
        }

        if ($this->kind === self::KIND_PARSE) {
            return 502;
        }

        if ($this->kind === self::KIND_CONNECTION) {
            return $this->statusCode === 504 ? 504 : 502;
        }

        return match ($this->statusCode) {
            503, 401, 403 => 503,
            429 => 429,
            504 => 504,
            default => 502,
        };
    }
}
