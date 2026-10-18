<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required'],
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
}
