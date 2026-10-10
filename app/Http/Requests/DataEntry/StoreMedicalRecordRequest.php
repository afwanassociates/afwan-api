<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalResult;
use App\Support\LocalDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Only medical_date, result, remarks, the medical centre and the slip are
 * accepted: valid_until and the tracking columns are always derived in
 * App\Services\MedicalService.
 */
class StoreMedicalRecordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        // "Today" is the Dhaka calendar date, not UTC's.
        $today = LocalDate::today();

        return [
            'medical_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.$today->toDateString(),
                'after_or_equal:'.$today->subYear()->toDateString(),
            ],
            'result' => ['required', Rule::enum(MedicalResult::class)],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'medical_center_id' => ['nullable', 'integer', $this->selectableCenter()],
            // The medical slip (MYGRAM). No ordering rule against medical_date.
            'slip_no' => ['nullable', 'string', 'max:50'],
            'slip_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The centre must exist and be active (not deactivated, not deleted).
     * When editing, the record's current centre is still accepted.
     */
    protected function selectableCenter(): Exists
    {
        $current = $this->route('medical')?->medical_center_id;

        return Rule::exists('medical_centers', 'id')->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->when($current, fn (Builder $query) => $query->orWhere('id', $current)));
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medical_date.before_or_equal' => 'The medical date cannot be in the future.',
            'medical_date.after_or_equal' => 'The medical date cannot be more than 1 year ago.',
            'result.enum' => 'The result must be fit or unfit.',
            'medical_center_id.exists' => 'The selected medical centre does not exist or is inactive.',
        ];
    }
}
