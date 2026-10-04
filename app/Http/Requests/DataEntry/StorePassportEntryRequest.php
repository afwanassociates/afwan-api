<?php

namespace App\Http\Requests\DataEntry;

use App\Models\PassportEntry;
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
        foreach (['passport_number' => 'passportNumber', 'passport_name' => 'passportName'] as $field => $normalizer) {
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
            'reference_id' => ['required', 'integer', $this->selectable('references', $this->entry()?->reference_id)],
            'company_id' => ['required', 'integer', $this->selectable('companies', $this->entry()?->company_id)],
            'passport_received_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
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
            'passport_number.unique' => 'This passport number is already entered.',
            'passport_number.regex' => 'The passport number must be 6 to 20 letters and digits.',
            'reference_id.exists' => 'The selected reference does not exist or is inactive.',
            'company_id.exists' => 'The selected company does not exist or is inactive.',
            'passport_received_date.before_or_equal' => 'The passport received date cannot be in the future.',
        ];
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
