<?php

namespace App\Http\Requests\Reports;

use App\Enums\PartyKind;
use App\Enums\PartyType;
use App\Support\ReferenceReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceReportRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request. sort takes a "-"
     * prefix for descending order, e.g. sort=-total.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $sortable = app(ReferenceReport::class)->sortable();

        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::enum(PartyKind::class)],
            // Filter by reference / by company (soft-deleted companies included).
            'reference_id' => ['sometimes', 'nullable', 'integer', Rule::exists('parties', 'id')->where('type', PartyType::Client->value)],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'sort' => ['sometimes', 'nullable', Rule::in([...$sortable, ...array_map(fn (string $column) => "-{$column}", $sortable)])],
            'export' => ['sometimes', 'nullable', Rule::in(['csv'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
