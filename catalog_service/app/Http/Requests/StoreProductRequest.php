<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('gateway')->check();
    }

    /**
     * Правила валидации для создания товара (POST /products)
     */
    public function rules(): array
    {
        return [
            'store_id' => 'required|integer|exists:stores,id',
            'category_id' => 'required|integer|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
        ];
    }
}
