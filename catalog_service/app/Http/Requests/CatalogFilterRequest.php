<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class CatalogFilterRequest extends FormRequest
{
    /**
     * Правила валидации для GET-параметров фильтра
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            // max_price не может быть меньше min_price
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'status' => ['nullable', 'string', 'in:in_stock'],
            'sort' => ['nullable', 'string', 'in:new,price_asc,price_desc'],
        ];
    }
}
