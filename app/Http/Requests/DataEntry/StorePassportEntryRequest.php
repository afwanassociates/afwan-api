<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalStatus;
use App\Models\PassportEntry;
use App\Rules\ActiveCountry;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

class StorePassportEntryRequest extends FormRequest
{
    /**
     * Normalize before validating, so the format and uniqueness checks see
     * the value that will be stored.
     */
    protected function prepareForValidation(): void
    {
        foreach (['passport_number' => 'passportNumber', 'passport_name' => 'passportName', 'country_code' => 'countryCode'] as $field => $normalizer) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::$normalizer($this->input($field))]);
            }
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
            'passport_name' => ['required', 'string', 'max:150'],
            'passport_number' => ['required', 'string', 'regex:/^[A-Z0-9]{6,20}$/', $this->uniquePassportNumber()],
            'country_code' => ['required', 'string', 'size:2', new ActiveCountry($this->entry()?->country_code)],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            // Required (must exist and be active). Old entries saved without one
            // still load; the full edit form asks for it on the next save.
            'reference_id' => ['required', 'integer', $this->selectable('references', $this->entry()?->reference_id)],
            'company_id' => ['required', 'integer', $this->selectable('companies', $this->entry()?->company_id)],
            'passport_received_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'passport_expiry_date' => ['required', 'date_format:Y-m-d', $this->afterReceivedDate()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'passport_number.unique' => $this->duplicatePassportNumberMessage(),
            'passport_number.regex' => 'The passport number must be 6 to 20 letters and digits.',
            'reference_id.required' => 'Please choose a reference.',
            'reference_id.exists' => 'The selected reference does not exist or is inactive.',
            'company_id.exists' => 'The selected company does not exist or is inactive.',
            'passport_received_date.before_or_equal' => 'The passport received date cannot be in the future.',
            'date_of_birth.before' => 'The date of birth must be in the past.',
            'passport_expiry_date.after' => 'The passport expiry date must be after the passport received date.',
        ];
    }

    /**
     * Say where the existing entry is, so staff can find it, without revealing
     * anything else about it.
     */
    protected function duplicatePassportNumberMessage(): string
    {
        $number = $this->input('passport_number');
        $existing = is_string($number) && $number !== ''
            ? PassportEntry::withTrashed()
                ->with('currentMedical')
                ->where('passport_number', $number)
                ->when($this->entry(), fn ($query, PassportEntry $entry) => $query->whereKeyNot($entry->id))
                ->first(['id', 'current_medical_id', 'deleted_at'])
            : null;

        $location = match (true) {
            $existing === null => null,
            $existing->trashed() => 'it is in the deleted records',
            $existing->medicalStatus() === MedicalStatus::Unfit => 'it is in the Unfit list',
            default => 'it is in the Passport list',
        };

        return $location === null
            ? 'This passport number is already entered.'
            : "This passport number is already entered ({$location}).";
    }

    /**
     * Compare with the received date being sent, or (when editing without
     * sending it) the entry's current one.
     */
    protected function afterReceivedDate(): string
    {
        $received = $this->input('passport_received_date', $this->entry()?->passport_received_date?->toDateString());

        return is_string($received) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)
            ? "after:{$received}"
            : 'after:passport_received_date';
    }

    /**
     * Unique among all entries, soft-deleted ones included.
     */
    protected function uniquePassportNumber(): Unique
    {
        return Rule::unique('passport_entries', 'passport_number')->ignore($this->entry());
    }

    /**
     * The row must exist and be active (not deactivated, not deleted). When
     * editing, the entry's current value is still accepted.
     */
    protected function selectable(string $table, ?int $current): Exists
    {
        return Rule::exists($table, 'id')->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->when($current, fn (Builder $query) => $query->orWhere('id', $current)));
    }

    /**
     * The entry being edited, or null when creating.
     */
    public function entry(): ?PassportEntry
    {
        return $this->route('passport');
    }
}
