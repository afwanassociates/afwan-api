<?php

namespace App\Http\Requests\Accounts;

use App\Enums\ExpenseCategory;
use App\Rules\PassportScanFile;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record or edit a company bill an owner paid with a loan the company gave
 * them. A copy of the bill (JPG, PNG or PDF, at most 5 MB) is required when
 * the bill is recorded; when editing, a new copy replaces the old one.
 */
class StoreOwnerLoanBillRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['purpose', 'bill_no', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field)) ?: null]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'expense_category' => ['required', Rule::in(ExpenseCategory::values())],
            'purpose' => ['required', 'string', 'max:150'],
            'bill_no' => ['nullable', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'note' => ['nullable', 'string', 'max:500'],
            'bill_copy' => PassportScanFile::rules(required: $this->route('bill') === null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Choose the date of the bill.',
            'expense_category.required' => 'Choose what the bill was for.',
            'expense_category.in' => 'Choose what the bill was for.',
            'purpose.required' => 'Enter what the bill was (e.g. October office rent).',
            'amount.required' => 'Enter the bill amount.',
            'amount.gt' => 'The amount must be more than 0.',
            'amount.decimal' => 'The amount can have at most 2 decimal places.',
            'bill_copy.required' => 'Attach a copy of the bill (photo or PDF).',
            'bill_copy.mimes' => 'The bill copy must be a JPG, PNG or PDF file.',
            'bill_copy.max' => 'The bill copy can be at most 5 MB.',
        ];
    }
}
