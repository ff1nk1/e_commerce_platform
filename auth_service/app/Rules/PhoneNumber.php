<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * E.164 phone number (e.g. +48123456789).
 *
 * Validation rules cannot rewrite input, so FormRequests call {@see self::normalize()}
 * from prepareForValidation() before this rule runs.
 */
final class PhoneNumber implements ValidationRule
{
    private const MAX_LENGTH = 16;

    private const E164_PATTERN = '/^\+[1-9]\d{9,14}$/';

    private const FORMATTING_CHARS = '/[\s\-()]/';

    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? preg_replace(self::FORMATTING_CHARS, '', $value) : $value;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Номер телефона должен быть в формате E.164 (например +48123456789).');

            return;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $fail('Номер телефона слишком длинный.');

            return;
        }

        if (! preg_match(self::E164_PATTERN, $value)) {
            $fail('Номер телефона должен быть в формате E.164 (например +48123456789).');
        }
    }
}
