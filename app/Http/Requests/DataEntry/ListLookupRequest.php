<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\ReferenceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Search for the reference and company dropdowns, or (admins, all=1) the
 * full paginated list including inactive rows.
 */
class ListLookupRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(ReferenceType::class)],
            // References only: those with passports of this company.
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'all' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
