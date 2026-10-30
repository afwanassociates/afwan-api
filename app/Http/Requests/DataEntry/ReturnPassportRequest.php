<?php

namespace App\Http\Requests\DataEntry;

use App\Support\LocalDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Returning a passport to its owner: who collected it and when (today or
 * earlier in Dhaka). The reference is the passport's own and is not sent.
 */
class ReturnPassportRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'return_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.LocalDate::today()->toDateString()],
            'receiver_name' => ['required', 'string', 'max:100'],
            'receiver_phone' => ['required', 'string', 'max:30'],
            'receiver_id_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'return_date.before_or_equal' => 'The return date cannot be in the future.',
            'receiver_name.required' => 'Enter the name of the person collecting the passport.',
            'receiver_phone.required' => 'Enter the receiver’s phone number.',
        ];
    }

    /**
     * Trim the text fields; empty optional ones become null.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['receiver_name', 'receiver_phone', 'receiver_id_no', 'remarks'])
            ->filter(fn (string $field) => $this->has($field))
            ->mapWithKeys(function (string $field) {
                $value = $this->input($field);
                $value = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value) ?? '') : $value;

                return [$field => $value === '' ? null : $value];
            })
            ->all());
    }
}
