<?php

namespace App\Http\Requests\DataEntry;

use App\Support\StepList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A step's work list. The order is fixed per tab: sort is ignored.
 */
class ListStepPassportsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // The step's own tabs (calling: pending, permitted).
            'tab' => ['sometimes', 'nullable', Rule::in(app(StepList::class)->tabKeys((string) $this->route('step')))],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            // The Filter by company / Filter by reference dropdowns.
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'reference_id' => ['sometimes', 'nullable', 'integer', 'exists:references,id'],
            'receive_date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'receive_date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'booking_date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'booking_date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            // The whole tab (same filters, every page) as a CSV download.
            'export' => ['sometimes', 'nullable', Rule::in(['csv'])],
            'sort' => ['exclude'],
            'direction' => ['exclude'],
        ];
    }
}
