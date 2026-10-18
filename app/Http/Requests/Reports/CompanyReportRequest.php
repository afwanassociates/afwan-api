<?php

namespace App\Http\Requests\Reports;

use App\Support\CompanyReport;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyReportRequest extends FormRequest
{
    /**
     * Accept the country code in any case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => Normalize::countryCode($this->input('country_code'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request. sort takes a "-"
     * prefix for descending order, e.g. sort=-total.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $sortable = app(CompanyReport::class)->sortable();

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Filter by company / by reference (soft-deleted references included).
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'reference_id' => ['sometimes', 'nullable', 'integer', 'exists:references,id'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'sort' => ['sometimes', 'nullable', Rule::in([...$sortable, ...array_map(fn (string $column) => "-{$column}", $sortable)])],
            'export' => ['sometimes', 'nullable', Rule::in(['csv'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
