<?php

namespace App\Http\Requests\Accounts;

use App\Enums\CashReceiptType;
use App\Enums\PartyType;
use App\Enums\PaymentMethod;
use App\Models\CashReceipt;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record or edit a cash receipt (the whole form is sent both times).
 * "Received from" (party_type) picks a party or an owner; the chosen
 * party must be of that type and active (when editing, the receipt's
 * current party stays valid even if deactivated since).
 */
class StoreCashReceiptRequest extends FormRequest
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
        $currentPartyId = $this->receipt()?->party_id;

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'party_type' => ['required', Rule::in(PartyType::values())],
            'party_id' => [
                'required',
                'integer',
                Rule::exists('parties', 'id')->where(function ($query) use ($currentPartyId) {
                    $query->where('type', $this->input('party_type'))
                        ->where(fn ($q) => $q->where('is_active', true)->when($currentPartyId, fn ($q, $id) => $q->orWhere('id', $id)));
                }),
            ],
            'receipt_type' => ['required', Rule::in(CashReceiptType::values())],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'bank_name' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:150'],
            'bank_branch' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:150'],
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
            'party_type.required' => 'Choose whether the money is from a party or an owner.',
            'party_id.required' => 'Choose who the money is from.',
            'party_id.exists' => 'Choose an active '.($this->input('party_type') === PartyType::Owner->value ? 'owner' : 'party').'.',
            'receipt_type.required' => 'Choose cash or loan.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be more than 0.',
            'payment_method.required' => 'Choose cash in hand or bank.',
            'bank_name.required_if' => 'Enter the bank name.',
            'bank_branch.required_if' => 'Enter the branch name.',
        ];
    }

    /**
     * The receipt being edited, or null when creating.
     */
    public function receipt(): ?CashReceipt
    {
        return $this->route('cash_receipt');
    }
}
