<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\Catalog\ProductFilters;
use App\Enums\CatalogAvailabilityFilter;
use App\Enums\ProductSort;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'status' => ['nullable', 'string', Rule::enum(CatalogAvailabilityFilter::class)],
            'sort' => ['nullable', 'string', Rule::enum(ProductSort::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): ProductFilters
    {
        return ProductFilters::fromArray($this->validated());
    }
}
