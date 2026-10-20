<?php

namespace App\Http\Requests\Accounts;

use App\Enums\PartyTransactionType;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A receivable line entered by hand on a party ledger (refunds are cash payments).
 */
class StorePartyTransactionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => Normalize::name($this->input('note')) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(PartyTransactionType::manualValues())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
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
            'type.required' => 'Choose the entry type (receivable).',
            'type.in' => 'Only a receivable can be entered here; record payments as cash receipts and refunds as cash payments.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be more than 0.',
        ];
    }
}
