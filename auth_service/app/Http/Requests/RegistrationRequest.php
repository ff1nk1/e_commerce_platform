<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RegistrationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('number')) {
            $this->merge(['number' => PhoneNormalizer::toE164($this->input('number'))]);
        }
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
            'number' => ['required', 'string', 'phone:INTERNATIONAL'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.required' => 'Укажите номер телефона.',
            'number.phone' => 'Номер телефона должен быть в формате E.164 (например +48123456789).',
        ];
    }
}
