<?php

namespace App\Http\Requests\Accounts;

use App\Enums\ExpenseCategory;
use App\Services\ExpenseReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListExpensesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Description, bill no., who was paid, payment or loan number.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'category' => ['sometimes', 'nullable', Rule::in(ExpenseCategory::values())],
            'source' => ['sometimes', 'nullable', Rule::in([ExpenseReport::SOURCE_DIRECT, ExpenseReport::SOURCE_OWNER_LOAN])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'export' => ['sometimes', Rule::in(['csv'])],
        ];
    }
}
