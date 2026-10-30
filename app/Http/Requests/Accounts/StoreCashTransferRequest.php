<?php

namespace App\Http\Requests\Accounts;

use App\Enums\TransferDirection;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cash deposited to a bank, or withdrawn from a bank into cash. The bank is
 * typed (name and branch) until Bank Management exists.
 */
class StoreCashTransferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['bank_name', 'bank_branch', 'note'] as $field) {
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
            'direction' => ['required', Rule::in(TransferDirection::values())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'bank_name' => ['required', 'string', 'max:150'],
            'bank_branch' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Choose the date.',
            'direction.required' => 'Choose deposit to bank or withdrawal from bank.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be more than 0.',
            'bank_name.required' => 'Enter the bank name.',
            'bank_branch.required' => 'Enter the branch name.',
        ];
    }
}
