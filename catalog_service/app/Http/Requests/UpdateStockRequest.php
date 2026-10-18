<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::guard('gateway')->check();
    }

    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:0',
        ];
    }
}
