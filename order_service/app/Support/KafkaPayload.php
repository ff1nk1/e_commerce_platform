<?php

declare(strict_types=1);

namespace App\Support;

final class KafkaPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sagaId(array $payload): ?string
    {
        $sagaId = $payload['saga_id'] ?? null;

        return is_string($sagaId) && $sagaId !== '' ? $sagaId : null;
    }
}
