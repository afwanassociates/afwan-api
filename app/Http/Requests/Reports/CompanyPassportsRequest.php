<?php

namespace App\Http\Requests\Reports;

use App\Support\Workflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyPassportsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'stage' => ['sometimes', Rule::in(app(Workflow::class)->stages())],
            'stage_status' => ['sometimes', Rule::in(Workflow::STAGE_STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
