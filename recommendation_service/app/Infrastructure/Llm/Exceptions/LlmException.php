<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm\Exceptions;

use App\Enums\LlmErrorKind;
use RuntimeException;
use Throwable;

final class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly LlmErrorKind $kind = LlmErrorKind::Http,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function configuration(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, LlmErrorKind::Configuration, $previous);
    }

    public static function parse(string $message, ?Throwable $previous = null): self
    {
        return new self($message, null, LlmErrorKind::Parse, $previous);
    }

    public static function connection(string $message, ?int $statusCode = null, ?Throwable $previous = null): self
    {
        return new self($message, $statusCode, LlmErrorKind::Connection, $previous);
    }

    public static function http(string $message, int $statusCode, ?Throwable $previous = null): self
    {
        return new self($message, $statusCode, LlmErrorKind::Http, $previous);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function kind(): LlmErrorKind
    {
        return $this->kind;
    }

    public function userMessage(): string
    {
        if ($this->kind === LlmErrorKind::Configuration) {
            return 'Сервис рекомендаций временно недоступен.';
        }

        if ($this->kind === LlmErrorKind::Parse) {
            return 'Не удалось обработать ответ ИИ. Попробуйте переформулировать запрос.';
        }

        if ($this->kind === LlmErrorKind::Connection) {
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
        if ($this->kind === LlmErrorKind::Configuration) {
            return 503;
        }

        if ($this->kind === LlmErrorKind::Parse) {
            return 502;
        }

        if ($this->kind === LlmErrorKind::Connection) {
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
