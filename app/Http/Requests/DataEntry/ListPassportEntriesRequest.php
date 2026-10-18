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
     * mode value for the Medical page: one tab (medical_status) at a time,
     * ordered per tab; sort is ignored.
     */
    public const MEDICAL = 'medical';

    /**
     * The Medical page's tabs (medical_status values allowed with mode=medical).
     *
     * @var list<string>
     */
    public const MEDICAL_TABS = ['pending', 'fit', 'expiring_soon', 'expired', 'unfit'];

    /**
     * Medical tabs holding only passports not yet marked Online.
     *
     * @var list<string>
     */
    public const UNMARKED_TABS = ['fit', 'expiring_soon'];

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
            'medical_status' => match (true) {
                $this->isPassportEntered() => ['exclude'],
                // The Medical page's tab (pending when not sent).
                $this->isMedicalMode() => ['sometimes', Rule::in(self::MEDICAL_TABS)],
                default => ['sometimes', Rule::in([...array_column(MedicalStatus::cases(), 'value'), self::ALL_MEDICAL_STATUSES])],
            },
            // Named list modes: passport_entered ignores medical_status; medical
            // takes it as the tab.
            'mode' => ['sometimes', 'nullable', Rule::in([self::PASSPORT_ENTERED, self::MEDICAL])],
            // Where the passport is in the workflow (stored by WorkflowService).
            // The overview (All Passports) offers no "completed" stage filter.
            'stage' => ['sometimes', Rule::in($overview ? array_values(array_diff($stages, [Workflow::COMPLETED])) : $stages)],
            'stage_status' => ['sometimes', Rule::in(Workflow::STAGE_STATUSES)],
            'received_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:received_from'],
            // The overview and the Medical page ignore sorting (fixed order).
            'sort' => $overview || $this->isMedicalMode() ? ['exclude'] : ['sometimes', Rule::in(self::SORTABLE)],
            'direction' => $overview || $this->isMedicalMode() ? ['exclude'] : ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'view' => ['sometimes', 'nullable', Rule::in([self::OVERVIEW])],
            // The overview only: the whole list (same searches) as a CSV download.
            'export' => $overview ? ['sometimes', 'nullable', Rule::in(['csv'])] : ['exclude'],
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
     * Whether the Medical page mode is asked for.
     */
    public function isMedicalMode(): bool
    {
        return $this->input('mode') === self::MEDICAL;
    }

    /**
     * Whether the lean All Passports overview is asked for.
     */
    public function isOverview(): bool
    {
        // GET /reports/passports (accounts included) only ever serves the overview.
        return $this->routeIs('reports.passports') || $this->input('view') === self::OVERVIEW;
    }
}
