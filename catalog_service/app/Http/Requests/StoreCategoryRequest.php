<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('gateway')->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'exists:categories,id'],
        ];
    }
}
