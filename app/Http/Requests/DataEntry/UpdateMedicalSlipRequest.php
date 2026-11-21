<?php

namespace App\Http\Requests\DataEntry;

use App\Models\PassportEntry;
use App\Support\LocalDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The passport's medical slip (the client's "MYGRAM"). medical_slip_date must
 * be sent; null clears it (only allowed before a result is recorded, checked
 * in App\Services\MedicalService). No ordering rule against the medical date.
 *
 * medical_date and medical_center_id are required with a slip date: the date
 * (today or earlier) the passport goes / went for its medical, and its centre.
 */
class UpdateMedicalSlipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'medical_slip_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            // Saving a slip needs its medical date and centre (clearing the slip, null, does not).
            'medical_center_id' => ['required_with:medical_slip_date', 'nullable', 'integer', $this->selectableCenter()],
            // "Today" is the Dhaka calendar date, not UTC's.
            'medical_date' => ['required_with:medical_slip_date', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.LocalDate::today()->toDateString()],
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
            'medical_slip_date.present' => 'The medical slip date is required.',
            'medical_date.required_with' => 'Enter the medical date.',
            'medical_center_id.required_with' => 'Choose the medical center.',
            'medical_date.before_or_equal' => 'The medical date cannot be in the future.',
            'medical_center_id.exists' => 'The selected medical centre does not exist or is inactive.',
        ];
    }

    /**
     * The centre must exist and be active (not deactivated, not deleted). The
     * passport's current centre is still accepted.
     */
    protected function selectableCenter(): Exists
    {
        $current = $this->passport()->medical_center_id;

        return Rule::exists('medical_centers', 'id')->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
            ->when($current, fn (Builder $query) => $query->orWhere('id', $current)));
    }

    public function passport(): PassportEntry
    {
        return $this->route('passport');
    }
}
