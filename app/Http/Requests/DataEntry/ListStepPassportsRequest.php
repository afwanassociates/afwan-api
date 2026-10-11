<?php

namespace App\Http\Requests\DataEntry;

use App\Support\StepList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A step's work list. The order is fixed per tab: sort is ignored.
 */
class ListStepPassportsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'tab' => ['sometimes', 'nullable', Rule::in(StepList::TABS)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['exclude'],
            'direction' => ['exclude'],
        ];
    }
}
