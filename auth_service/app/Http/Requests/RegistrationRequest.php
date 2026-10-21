<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:30'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'number' => ['required', 'string', 'max:16', 'regex:/^\+[1-9]\d{9,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.required' => 'Укажите номер телефона.',
            'number.regex' => 'Номер телефона должен быть в формате E.164 (например +48123456789).',
            'number.max' => 'Номер телефона слишком длинный.',
        ];
    }
}
