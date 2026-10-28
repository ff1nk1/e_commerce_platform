<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class GetUploadUrlRequest extends FormRequest
{
    public const ALLOWED_CONTENT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public const CONTENT_TYPE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

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
            'filename' => [
                'required',
                'string',
                'max:255',
                'regex:/\.(jpe?g|png|webp|gif)$/i',
            ],
            'content_type' => [
                'required',
                'string',
                Rule::in(self::ALLOWED_CONTENT_TYPES),
            ],
        ];
    }
}
