<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class StoreStockRequest extends FormRequest
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
            'product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],
            'store_id' => [
                'required',
                'integer',
                'exists:stores,id',
                Rule::unique('stocks')->where(
                    fn ($query) => $query->where('product_id', $this->input('product_id'))
                ),
            ],
            'quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
