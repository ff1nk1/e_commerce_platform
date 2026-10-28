<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize phone input before validation.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('number') || ! is_string($this->input('number'))) {
            return;
        }

        $normalized = preg_replace('/[\s\-()]/', '', $this->input('number'));

        $this->merge([
            'number' => $normalized,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'number' => ['sometimes', 'string', 'max:16', 'regex:/^\+[1-9]\d{9,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.regex' => 'Номер телефона должен быть в формате E.164 (например +48123456789).',
            'number.max' => 'Номер телефона слишком длинный.',
        ];
    }
}
