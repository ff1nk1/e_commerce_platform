<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('gateway')->check();
    }

    public function rules(): array
    {
        // Получаем текущую категорию из роута, чтобы игнорировать её собственный slug при проверке на уникальность
        $categoryId = $this->route('category')->id;

        return [
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|unique:categories,slug,'.$categoryId,
            'parent_id' => 'nullable|exists:categories,id',
        ];
    }
}
