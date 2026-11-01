<?php

namespace App\Http\Requests\Accounts;

use App\Enums\CashPaymentType;
use App\Enums\ExpenseCategory;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Accounts\Concerns\ValidatesMobileBanking;
use App\Models\CashPayment;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record or edit a cash payment (the whole form is sent both times).
 * "Paid to" (payee_type) is a party, an owner or another payee. A party or
 * owner must be active (when editing, the payment's current party stays
 * valid); another payee is typed with the expense category, the purpose
 * and optionally their bill / receipt number.
 */
class StoreCashPaymentRequest extends FormRequest
{
    use ValidatesMobileBanking;

    protected function prepareForValidation(): void
    {
        foreach (['payee_name', 'purpose', 'bill_no', 'bank_name', 'bank_branch', 'bank_account_name', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field)) ?: null]);
            }
        }
        if (is_string($this->input('bank_account_number'))) {
            $this->merge(['bank_account_number' => preg_replace('/\s+/', '', $this->input('bank_account_number')) ?: null]);
        }
        $this->normalizeMobileNumber();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $payee = PayeeType::tryFrom((string) $this->input('payee_type'));
        $currentPartyId = $this->payment()?->party_id;
        $isOther = $payee === PayeeType::Other;
        $allowedTypes = $payee ? array_map(fn (CashPaymentType $t) => $t->value, $payee->paymentTypes()) : CashPaymentType::values();
        // A party loan repayment saved before only owners could lend may keep its type while edited.
        $current = $this->payment();
        if ($payee === PayeeType::Client && $current?->party?->type->value === 'client' && $current->payment_type === CashPaymentType::LoanRepayment) {
            $allowedTypes[] = CashPaymentType::LoanRepayment->value;
        }

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'payee_type' => ['required', Rule::in(PayeeType::values())],
            'party_id' => $isOther ? ['nullable'] : [
                'required',
                'integer',
                Rule::exists('parties', 'id')->where(function ($query) use ($payee, $currentPartyId) {
                    $query->where('type', $payee?->partyType()?->value)
                        ->where(fn ($q) => $q->where('is_active', true)->when($currentPartyId, fn ($q, $id) => $q->orWhere('id', $id)));
                }),
            ],
            'payee_name' => $isOther ? ['required', 'string', 'max:150'] : ['nullable'],
            'payment_type' => [
                'required',
                Rule::in($allowedTypes),
            ],
            'purpose' => [$isOther ? 'required' : 'nullable', 'string', 'max:150'],
            // An owner loan repayment names the loan it pays back (App\Services\OwnerLoans).
            'loan_receipt_id' => $payee === PayeeType::Owner && $this->input('payment_type') === CashPaymentType::LoanRepayment->value
                ? ['required', 'integer']
                : ['nullable'],
            'expense_category' => $isOther ? ['required', Rule::in(ExpenseCategory::values())] : ['nullable'],
            'bill_no' => ['nullable', 'string', 'max:50'],
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
        $payee = PayeeType::tryFrom((string) $this->input('payee_type'));
        $allowed = $payee
            ? implode(' or ', array_map(fn (CashPaymentType $t) => strtolower($t->label()), $payee->paymentTypes()))
            : '';

        return [
            'date.required' => 'Choose the date.',
            'payee_type.required' => 'Choose who the money is paid to.',
            'party_id.required' => 'Choose who the money is paid to.',
            'party_id.exists' => 'Choose an active '.($payee === PayeeType::Owner ? 'owner' : 'party').'.',
            'payee_name.required' => 'Enter the name of who you paid.',
            'loan_receipt_id.required' => 'Choose the loan being repaid.',
            'expense_category.required' => 'Choose what the money was spent on.',
            'expense_category.in' => 'Choose what the money was spent on.',
            'payment_type.required' => 'Choose what the payment is for.',
            'payment_type.in' => $allowed !== '' ? "A payment to this payee can only be a {$allowed}." : 'Choose what the payment is for.',
            'purpose.required' => 'Enter the purpose (e.g. office rent).',
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
        return 'paid';
    }

    /**
     * The payment being edited, or null when creating.
     */
    public function payment(): ?CashPayment
    {
        return $this->route('cash_payment');
    }
}
