<?php

declare(strict_types=1);

namespace App\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Normalizes a user supplied phone number to E.164 (e.g. +48123456789).
 *
 * Validation rules cannot rewrite input, so FormRequests call this from
 * prepareForValidation() before the phone rule runs. Unparsable input is
 * returned untouched so the validator can reject it with a clear error.
 */
final class PhoneNormalizer
{
    public static function toE164(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        try {
            $phone = new PhoneNumber($trimmed);
            if ($phone->isValid()) {
                return $phone->formatE164();
            }
        } catch (Throwable) {
            // Leave the raw value; the phone rule will fail validation.
        }

        return $value;
    }
}
