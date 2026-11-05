<?php

namespace App\Http\Requests\Accounts;

use App\Enums\BusinessType;
use App\Enums\CashReceiptType;
use App\Enums\PartyType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Accounts\Concerns\ValidatesMobileBanking;
use App\Models\CashReceipt;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record or edit a cash receipt (the whole form is sent both times).
 * "Received from" (party_type) picks a party or an owner. An owner's money
 * is capital, or the return of a loan the company gave them (naming the
 * loan); the chosen
 * party must be of that type and active (when editing, the receipt's
 * current party stays valid even if deactivated since). A party's receipt
 * names its business (recruitment, land, solar, railway); recruitment also
 * names the destination country. Both are ignored for owners.
 */
class StoreCashReceiptRequest extends FormRequest
{
    use ValidatesMobileBanking;

    protected function prepareForValidation(): void
    {
        foreach (['bank_name', 'bank_branch', 'bank_account_name', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field)) ?: null]);
            }
        }
        if (is_string($this->input('bank_account_number'))) {
            $this->merge(['bank_account_number' => preg_replace('/\s+/', '', $this->input('bank_account_number')) ?: null]);
        }
        $this->normalizeMobileNumber();
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper(trim($this->input('country_code'))) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $currentPartyId = $this->receipt()?->party_id;
        $currentCountry = $this->receipt()?->country_code;
        $isClient = $this->input('party_type') === PartyType::Client->value;
        $isRecruitment = $isClient && $this->input('business_type') === BusinessType::Recruitment->value;

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
            'receipt_type' => [
                'required',
                Rule::in(CashReceiptType::values()),
                // Only an owner returns a loan the company gave them.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === CashReceiptType::LoanReturn->value && $this->input('party_type') !== PartyType::Owner->value) {
                        $fail('Only a company owner returns a loan.');
                    }
                },
            ],
            // The loan a return settles (App\Services\OwnerLoans checks it is this owner's and outstanding).
            'owner_loan_id' => $this->input('receipt_type') === CashReceiptType::LoanReturn->value
                ? ['required', 'integer']
                : ['nullable'],
            'business_type' => $isClient
                ? ['required', Rule::in(BusinessType::values())]
                : ['nullable'],
            // An active country; when editing, the receipt's current one stays valid.
            'country_code' => $isRecruitment
                ? ['required', 'string', Rule::exists('countries', 'code')->where(
                    fn ($q) => $q->where('is_active', true)->when($currentCountry, fn ($q, $code) => $q->orWhere('code', $code))
                )]
                : ['nullable'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'bank_name' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:150'],
            'bank_branch' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:150'],
            'bank_account_name' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'required_if:payment_method,'.PaymentMethod::Bank->value, 'string', 'max:30', 'regex:/^[0-9A-Za-z-]+$/'],
            ...$this->mobileBankingRules(),
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
            'receipt_type.required' => 'Choose what the money is.',
            'owner_loan_id.required' => 'Choose the loan being returned.',
            'business_type.required' => 'Choose the business.',
            'business_type.in' => 'Choose the business.',
            'country_code.required' => 'Choose the country.',
            'country_code.exists' => 'Choose an active country.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be more than 0.',
            'bank_name.required_if' => 'Enter the bank name.',
            'bank_branch.required_if' => 'Enter the branch name.',
            'bank_account_name.required_if' => 'Enter the bank account name.',
            'bank_account_number.required_if' => 'Enter the bank account number.',
            'bank_account_number.regex' => 'Use only digits, letters and dashes.',
            ...$this->mobileBankingMessages(),
        ];
    }

    protected function moneyVerb(): string
    {
        return 'received';
    }

    /**
     * The receipt being edited, or null when creating.
     */
    public function receipt(): ?CashReceipt
    {
        return $this->route('cash_receipt');
    }
}
