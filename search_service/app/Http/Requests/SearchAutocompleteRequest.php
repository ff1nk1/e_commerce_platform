<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchAutocompleteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'fuzziness' => ['nullable', Rule::in(['AUTO', '0', '1', '2'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('fuzziness') && $this->input('fuzziness') !== null && $this->input('fuzziness') !== '') {
            $this->merge([
                'fuzziness' => strtoupper((string) $this->input('fuzziness')),
            ]);
        }
    }

    /**
     * @return array{q: string|null, limit: int, fuzziness: string|int}
     */
    public function autocompleteParams(): array
    {
        $q = $this->input('q');
        $q = is_string($q) ? trim($q) : null;
        if ($q === '') {
            $q = null;
        }

        $fuzzinessRaw = (string) ($this->input('fuzziness') ?: 'AUTO');
        $fuzziness = in_array($fuzzinessRaw, ['0', '1', '2'], true)
            ? (int) $fuzzinessRaw
            : 'AUTO';

        return [
            'q' => $q,
            'limit' => min(20, max(1, (int) $this->input('limit', 8))),
            'fuzziness' => $fuzziness,
        ];
    }
}
