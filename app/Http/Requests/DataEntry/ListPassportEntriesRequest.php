<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalStatus;
use App\Enums\ReferenceType;
use App\Support\Normalize;
use App\Support\Workflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPassportEntriesRequest extends FormRequest
{
    /**
     * Columns the list may be sorted by.
     */
    public const SORTABLE = ['passport_received_date', 'created_at', 'medical_date', 'valid_until', 'status_date'];

    /**
     * view value for the lean All Passports overview (PassportOverviewResource).
     */
    public const OVERVIEW = 'overview';

    /**
     * medical_status value that lists passports of every status, unfit included.
     */
    public const ALL_MEDICAL_STATUSES = 'all';

    /**
     * Accept the country code in any case, and a "-" prefix on sort.
     */
    protected function prepareForValidation(): void
    {
        // "-status_date" is shorthand for sort=status_date&direction=desc.
        if (is_string($this->input('sort')) && str_starts_with($this->input('sort'), '-')) {
            $this->merge(['sort' => substr($this->input('sort'), 1), 'direction' => 'desc']);
        }

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
            // Without it, unfit passports are left out; "unfit" lists only them and
            // "all" lists every status (the All Passports screen).
            'medical_status' => ['sometimes', Rule::in([...array_column(MedicalStatus::cases(), 'value'), self::ALL_MEDICAL_STATUSES])],
            // Where the passport is in the workflow (stored by WorkflowService).
            'stage' => ['sometimes', Rule::in(app(Workflow::class)->stages())],
            'stage_status' => ['sometimes', Rule::in(Workflow::STAGE_STATUSES)],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            'sort' => ['sometimes', Rule::in(self::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'view' => ['sometimes', 'nullable', Rule::in([self::OVERVIEW])],
        ];
    }
}
