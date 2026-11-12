<?php

namespace App\Http\Requests\Accounts;

use App\Enums\BusinessType;
use App\Enums\CashReceiptType;
use App\Enums\PartyType;
use App\Enums\PaymentMethod;
use App\Http\Requests\Accounts\Concerns\ValidatesMobileBanking;
use App\Models\CashReceipt;
use App\Models\PassportEntry;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record or edit a cash receipt (the whole form is sent both times).
 * "Received from" (party_type) picks a party or an owner. An owner's money
 * is capital, or the return of a loan the company gave them (naming the
 * loan); the chosen
 * party must be of that type and active (when editing, the receipt's
 * current party stays valid even if deactivated since). A party's receipt
 * names its business (recruitment, land, solar, railway); recruitment also
 * names the destination country. Both are ignored for owners.
 *
 * A party's recruitment cash may say what it pays for (companies): per
 * company (of the receipt's country, the destination) the amount, the passports it pays and whether that pays them off
 * (full payment) or is a part payment. Those passports must be the party's,
 * of that company, and not paid off by another
 * receipt (part payments may stack); the companies' amounts add up to the
 * receipt's amount.
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
            // An active business; when editing, the receipt's current one stays valid.
            'business_type' => $isClient
                ? ['required', Rule::in(BusinessType::values()), function (string $attribute, mixed $value, \Closure $fail) {
                    $type = BusinessType::tryFrom((string) $value);
                    if ($type && ! $type->isActive() && $this->receipt()?->business_type !== $type) {
                        $fail("{$type->label()} is inactive. Choose an active business.");
                    }
                }]
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
            'companies' => $this->takesPassports() ? ['nullable', 'array', 'max:100'] : ['prohibited'],
            'companies.*.company_id' => ['required', 'integer', 'distinct'],
            'companies.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'companies.*.is_full_payment' => ['required', 'boolean'],
            'companies.*.passport_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'companies.*.passport_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Of these passports, those another receipt pays off (a full payment), with
     * that receipt's number.
     *
     * @param  list<int>  $passportIds
     * @return Collection<int, string>
     */
    public static function paidOffElsewhere(array $passportIds, ?int $receiptId): Collection
    {
        return DB::table('cash_receipt_passports')
            ->join('cash_receipt_lines', 'cash_receipt_lines.id', '=', 'cash_receipt_passports.cash_receipt_line_id')
            ->join('cash_receipts', 'cash_receipts.id', '=', 'cash_receipt_passports.cash_receipt_id')
            ->where('cash_receipt_lines.is_full_payment', true)
            ->whereIn('cash_receipt_passports.passport_entry_id', $passportIds)
            ->when($receiptId, fn ($q, $id) => $q->where('cash_receipt_passports.cash_receipt_id', '!=', $id))
            ->pluck('cash_receipts.receipt_no', 'cash_receipt_passports.passport_entry_id');
    }

    /**
     * Only a party's recruitment cash names companies and passports.
     */
    public function takesPassports(): bool
    {
        return $this->input('party_type') === PartyType::Client->value
            && $this->input('business_type') === BusinessType::Recruitment->value
            && $this->input('receipt_type') === CashReceiptType::Cash->value;
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $companies = $this->input('companies');
            if ($validator->errors()->isNotEmpty() || ! $this->takesPassports() || ! is_array($companies) || $companies === []) {
                return;
            }

            $total = array_sum(array_map(fn (array $line) => (float) $line['amount'], $companies));
            if (abs($total - (float) $this->input('amount')) > 0.004) {
                $validator->errors()->add('amount', 'The amount must equal the total of the companies ('.number_format($total, 2).').');
            }

            $receiptId = $this->receipt()?->id;
            $ids = array_merge(...array_map(fn (array $line) => array_map('intval', $line['passport_ids']), $companies));
            $passports = PassportEntry::withTrashed()
                ->whereIn('id', $ids)
                ->with(['company' => fn ($q) => $q->withTrashed()->select('id', 'country_code')])
                ->get(['id', 'entry_no', 'reference_id', 'company_id', 'deleted_at', 'price'])
                ->keyBy('id');
            $paidElsewhere = self::paidOffElsewhere($ids, $receiptId);
            $onThisReceipt = $receiptId
                ? DB::table('cash_receipt_passports')->where('cash_receipt_id', $receiptId)->pluck('passport_entry_id')->flip()
                : collect();

            foreach ($companies as $index => $line) {
                foreach ($line['passport_ids'] as $id) {
                    $passport = $passports->get((int) $id);
                    $message = match (true) {
                        $passport === null => 'A chosen passport no longer exists.',
                        $passport->trashed() && ! $onThisReceipt->has($passport->id) => "Passport #{$passport->entry_no} was deleted.",
                        $passport->reference_id !== (int) $this->input('party_id') => "Passport #{$passport->entry_no} is not this party's.",
                        $passport->company_id !== (int) $line['company_id'] => "Passport #{$passport->entry_no} is not for this company.",
                        // The destination is the company's country (the passport's own is the holder's nationality).
                        $passport->company?->country_code !== $this->input('country_code') => "Passport #{$passport->entry_no} is for another country.",
                        $paidElsewhere->has($passport->id) => "Passport #{$passport->entry_no} is already fully paid on {$paidElsewhere->get($passport->id)}.",
                        // Money is received only against a price: the company's standard rate or the party's rate.
                        $passport->price === null && ! $onThisReceipt->has($passport->id) => "Passport #{$passport->entry_no} has no price. The Accounts & Finance Head sets the company's standard rate or this party's rate first.",
                        default => null,
                    };
                    if ($message !== null) {
                        $validator->errors()->add("companies.{$index}.passport_ids", $message);
                    }
                }
            }
        }];
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
            'companies.prohibited' => 'Only a party\'s recruitment cash pays for passports.',
            'companies.*.company_id.distinct' => 'Each company only once.',
            'companies.*.amount.required' => 'Enter the amount for this company.',
            'companies.*.amount.gt' => 'The amount must be more than 0.',
            'companies.*.is_full_payment.required' => 'Choose full or part payment.',
            'companies.*.passport_ids.required' => 'Tick at least one passport.',
            'companies.*.passport_ids.min' => 'Tick at least one passport.',
            'companies.*.passport_ids.*.distinct' => 'A passport can be paid only once.',
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
