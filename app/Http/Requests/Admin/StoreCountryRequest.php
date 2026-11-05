<?php

namespace App\Http\Requests\Admin;

use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCountryRequest extends FormRequest
{
    /**
     * Normalize before the uniqueness checks.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Normalize::countryCode($this->input('code'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => Normalize::name($this->input('name'))]);
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
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'code')],
            'name' => ['required', 'string', 'max:100', Rule::unique('countries', 'name')],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
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
            'code.regex' => 'The country code must be exactly 2 letters.',
            'code.unique' => 'A country with this code already exists.',
            'name.unique' => 'A country with this name already exists.',
        ];
    }
}
