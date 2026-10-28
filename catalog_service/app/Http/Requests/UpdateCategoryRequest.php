<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class UpdateCategoryRequest extends FormRequest
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
        // Получаем текущую категорию из роута, чтобы игнорировать её собственный slug при проверке на уникальность
        $categoryId = $this->route('category')->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => 'sometimes|required|string|unique:categories,slug,'.$categoryId,
            'parent_id' => ['nullable', 'exists:categories,id'],
        ];
    }
}
