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
     * mode value for the Passport List page: only passports just entered
     * (medical not started: no slip date, no result).
     */
    public const PASSPORT_ENTERED = 'passport_entered';

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
        $overview = $this->isOverview();
        $stages = app(Workflow::class)->stages();

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Part of a reference's name, any case (combines with q).
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Part of a company's name, any case (combines with q and reference;
            // company_id stays for exact matches).
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_id' => ['sometimes', 'integer'],
            'reference_type' => ['sometimes', Rule::enum(ReferenceType::class)],
            'company_id' => ['sometimes', 'integer'],
            'company_country_code' => ['sometimes', 'string', 'size:2'],
            'received_from' => ['sometimes', 'date_format:Y-m-d'],
            // Without it, unfit passports are left out; "unfit" lists only them and
            // "all" lists every status (the All Passports screen).
            'medical_status' => $this->isPassportEntered()
                ? ['exclude']
                : ['sometimes', Rule::in([...array_column(MedicalStatus::cases(), 'value'), self::ALL_MEDICAL_STATUSES])],
            // A named list mode; medical_status is ignored with it.
            'mode' => ['sometimes', 'nullable', Rule::in([self::PASSPORT_ENTERED])],
            // Where the passport is in the workflow (stored by WorkflowService).
            // The overview (All Passports) offers no "completed" stage filter.
            'stage' => ['sometimes', Rule::in($overview ? array_values(array_diff($stages, [Workflow::COMPLETED])) : $stages)],
            'stage_status' => ['sometimes', Rule::in(Workflow::STAGE_STATUSES)],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            // The overview ignores sorting: always newest first.
            'sort' => $overview ? ['exclude'] : ['sometimes', Rule::in(self::SORTABLE)],
            'direction' => $overview ? ['exclude'] : ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'view' => ['sometimes', 'nullable', Rule::in([self::OVERVIEW])],
        ];
    }

    /**
     * Whether the Passport List mode (passports just entered) is asked for.
     */
    public function isPassportEntered(): bool
    {
        return $this->input('mode') === self::PASSPORT_ENTERED;
    }

    /**
     * Whether the lean All Passports overview is asked for.
     */
    public function isOverview(): bool
    {
        return $this->input('view') === self::OVERVIEW;
    }
}
