<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\ReferenceType;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPassportEntriesRequest extends FormRequest
{
    /**
     * Columns the list may be sorted by.
     */
    public const SORTABLE = ['passport_received_date', 'created_at'];

    /**
     * Accept the country code in any case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('company_country_code'))) {
            $this->merge(['company_country_code' => Normalize::countryCode($this->input('company_country_code'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_id' => ['sometimes', 'integer'],
            'reference_type' => ['sometimes', Rule::enum(ReferenceType::class)],
            'company_id' => ['sometimes', 'integer'],
            'company_country_code' => ['sometimes', 'string', 'size:2'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'sort' => ['sometimes', Rule::in(self::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
