<?php

namespace App\Http\Requests\Admin;

use App\Rules\ActiveCountry;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Accept country codes in any case.
     */
    protected function prepareForValidation(): void
    {
        foreach (['default_company_country_code', 'default_passport_country_code'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::countryCode($this->input($field))]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request (PUT: send both).
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'default_company_country_code' => ['required', 'string', 'size:2', new ActiveCountry],
            'default_passport_country_code' => ['present', 'nullable', 'string', 'size:2', new ActiveCountry],
        ];
    }
}
