<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChatRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $userId = $this->input('user_id');
        if (is_array($userId) || is_object($userId)) {
            $this->merge(['user_id' => null]);

            return;
        }

        if ($userId !== null && $userId !== '') {
            $this->merge(['user_id' => (string) $userId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:64'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'conversation_id' => ['sometimes', 'nullable', 'uuid'],
            'store_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'store_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'in_stock' => ['sometimes', 'nullable', 'boolean'],
            'price_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('price_min');
            $max = $this->input('price_max');
            if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max)
                && (float) $min > (float) $max) {
                $validator->errors()->add('price_min', 'price_min must be less than or equal to price_max.');
            }
        });
    }
}
