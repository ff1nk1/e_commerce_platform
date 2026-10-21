<?php

namespace Tests\Unit;

use App\Services\Llm\Exceptions\LlmException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LlmExceptionTest extends TestCase
{
    #[DataProvider('userFacingCases')]
    public function test_user_message_and_public_status(
        LlmException $exception,
        string $expectedMessage,
        int $expectedStatus,
    ): void {
        $this->assertSame($expectedMessage, $exception->userMessage());
        $this->assertSame($expectedStatus, $exception->publicHttpStatus());
    }

    /**
     * @return array<string, array{0: LlmException, 1: string, 2: int}>
     */
    public static function userFacingCases(): array
    {
        return [
            'high demand 503' => [
                LlmException::http('Gemini extractFilters failed (503): {"error":{}}', 503),
                'Сервис ИИ сейчас перегружен. Попробуйте чуть позже.',
                503,
            ],
            'rate limit 429' => [
                LlmException::http('Gemini chat failed (429): quota', 429),
                'Слишком много запросов к ИИ. Подождите минуту и попробуйте снова.',
                429,
            ],
            'deadline 504' => [
                LlmException::http('Gemini embed failed (504): deadline', 504),
                'ИИ слишком долго отвечает. Попробуйте позже.',
                504,
            ],
            'upstream 500' => [
                LlmException::http('Gemini chat failed (500): boom', 500),
                'Не удалось связаться с сервисом ИИ. Попробуйте ещё раз.',
                502,
            ],
            'bad request 400' => [
                LlmException::http('Gemini chat failed (400): bad', 400),
                'Не удалось обработать ответ ИИ. Попробуйте переформулировать запрос.',
                502,
            ],
            'auth 401' => [
                LlmException::http('Gemini chat failed (401): unauthorized', 401),
                'Сервис рекомендаций временно недоступен.',
                503,
            ],
            'auth 403' => [
                LlmException::http('Gemini chat failed (403): forbidden', 403),
                'Сервис рекомендаций временно недоступен.',
                503,
            ],
            'unknown http' => [
                LlmException::http('Gemini chat failed (418): teapot', 418),
                'Не удалось получить ответ от ИИ. Попробуйте ещё раз.',
                502,
            ],
            'parse' => [
                LlmException::parse('Gemini extractFilters returned invalid JSON'),
                'Не удалось обработать ответ ИИ. Попробуйте переформулировать запрос.',
                502,
            ],
            'configuration' => [
                LlmException::configuration('GEMINI_API_KEY is not configured'),
                'Сервис рекомендаций временно недоступен.',
                503,
            ],
            'connection timeout' => [
                LlmException::connection('Gemini request failed: cURL error 28: timed out', 504),
                'ИИ слишком долго отвечает. Попробуйте позже.',
                504,
            ],
            'connection error' => [
                LlmException::connection('Gemini request failed: Connection refused'),
                'Не удалось связаться с сервисом ИИ. Попробуйте ещё раз.',
                502,
            ],
        ];
    }

    public function test_technical_message_is_preserved_for_logs(): void
    {
        $technical = 'Gemini extractFilters failed (503): {"error":{"message":"high demand"}}';
        $exception = LlmException::http($technical, 503);

        $this->assertSame($technical, $exception->getMessage());
        $this->assertSame(503, $exception->statusCode());
        $this->assertSame(LlmException::KIND_HTTP, $exception->kind());
        $this->assertStringNotContainsString('high demand', $exception->userMessage());
        $this->assertStringNotContainsString('{', $exception->userMessage());
    }
}
