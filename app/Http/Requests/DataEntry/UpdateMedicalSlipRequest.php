<?php

namespace App\Http\Requests\DataEntry;

use App\Models\PassportEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The passport's medical slip (the client's "MYGRAM"). medical_slip_date must
 * be sent; null clears it (only allowed before a result is recorded, checked
 * in App\Services\MedicalService). No ordering rule against the medical date.
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
            'medical_slip_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'medical_center_id' => ['sometimes', 'nullable', 'integer', $this->selectableCenter()],
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
