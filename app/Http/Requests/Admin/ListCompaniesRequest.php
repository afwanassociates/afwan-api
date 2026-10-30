<?php

namespace App\Http\Requests\Admin;

use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListCompaniesRequest extends FormRequest
{
    /**
     * Accept the country code in any case.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => Normalize::countryCode($this->input('country_code'))]);
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
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
