<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreOrderRequest extends FormRequest
{
    public const IDEMPOTENCY_KEY_MAX = 128;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->header('Idempotency-Key');
            if (! is_string($key) || $key === '') {
                $validator->errors()->add('Idempotency-Key', 'Idempotency-Key header is required.');

                return;
            }

            if (strlen($key) > self::IDEMPOTENCY_KEY_MAX) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'Idempotency-Key must be at most '.self::IDEMPOTENCY_KEY_MAX.' characters.'
                );
            }
        });
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }
}
