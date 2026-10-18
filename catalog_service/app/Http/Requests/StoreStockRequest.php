<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('gateway')->check();
    }

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
            'quantity' => 'required|integer|min:0',
        ];
    }
}
