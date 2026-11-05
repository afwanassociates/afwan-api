<?php

namespace App\Http\Requests\Accounts;

use App\Enums\MobileBankingProvider;
use App\Enums\PaymentMethod;
use App\Enums\TransferDirection;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cash deposited to a bank or mobile banking wallet (channel), or withdrawn
 * from one into cash.
 *
 * Bank: the bank is typed (name and branch) until Bank Management exists. A
 * deposit also needs the deposit slip number and the depositor (name, mobile, NID or passport number); a withdrawal
 * needs the cheque number and the same three details of the person who
 * withdrew it ("Withdrawn by").
 *
 * Mobile banking (cash in / cash out): the service (bKash, Nagad…) and the
 * wallet number, and who did it (name and mobile; NID or passport optional).
 */
class StoreCashTransferRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['bank_name', 'bank_branch', 'cheque_no', 'deposit_slip_no', 'reference_name', 'reference_mobile', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field)) ?: null]);
            }
        }
        if (is_string($this->input('mobile_number'))) {
            $digits = preg_replace('/\D+/', '', $this->input('mobile_number'));
            $this->merge(['mobile_number' => preg_replace('/^880?(?=1)/', '0', $digits) ?: null]);
        }
        if (! $this->has('channel')) {
            $this->merge(['channel' => PaymentMethod::Bank->value]);
        }
        if (is_string($this->input('reference_nid_passport'))) {
            $this->merge(['reference_nid_passport' => Normalize::passportNumber($this->input('reference_nid_passport')) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $isDeposit = $this->input('direction') === TransferDirection::Deposit->value;
        $isBank = $this->input('channel') === PaymentMethod::Bank->value;
        // Required for a bank transfer when $required; otherwise optional.
        $bank = fn (bool $required, array $rules) => $isBank && $required ? ['required', ...$rules] : ['nullable', ...$rules];
        $mobile = fn (array $rules) => $isBank ? ['nullable', ...$rules] : ['required', ...$rules];

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'direction' => ['required', Rule::in(TransferDirection::values())],
            'channel' => ['required', Rule::in([PaymentMethod::Bank->value, PaymentMethod::MobileBanking->value])],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'bank_name' => $bank(true, ['string', 'max:150']),
            'bank_branch' => $bank(true, ['string', 'max:150']),
            'cheque_no' => $bank(! $isDeposit, ['string', 'max:50']),
            'deposit_slip_no' => $bank($isDeposit, ['string', 'max:50']),
            'mobile_provider' => $mobile([Rule::in(MobileBankingProvider::values())]),
            'mobile_number' => $mobile(['string', 'regex:/^01[3-9]\d{8}$/']),
            // The person who deposited the cash at, or withdrew it from, the bank or wallet.
            'reference_name' => ['required', 'string', 'max:150'],
            'reference_mobile' => ['required', 'string', 'max:30', 'regex:'.StorePartyRequest::MOBILE_PATTERN],
            'reference_nid_passport' => $bank(true, ['string', 'max:50']),
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $who = $this->input('direction') === TransferDirection::Deposit->value ? "the depositor's" : "the withdrawer's";

        return [
            'date.required' => 'Choose the date.',
            'direction.required' => 'Choose deposit or withdrawal.',
            'channel.required' => 'Choose bank or mobile banking.',
            'channel.in' => 'Choose bank or mobile banking.',
            'mobile_provider.required' => 'Choose the mobile banking service.',
            'mobile_provider.in' => 'Choose the mobile banking service.',
            'mobile_number.required' => 'Enter the mobile banking number.',
            'mobile_number.regex' => 'Enter an 11-digit mobile number, e.g. 01712345678.',
            'amount.required' => 'Enter the amount.',
            'amount.gt' => 'The amount must be more than 0.',
            'bank_name.required' => 'Enter the bank name.',
            'bank_branch.required' => 'Enter the branch name.',
            'cheque_no.required' => 'Enter the cheque number.',
            'deposit_slip_no.required' => 'Enter the deposit slip number.',
            'reference_name.required' => "Enter {$who} name.",
            'reference_mobile.required' => "Enter {$who} mobile number.",
            'reference_nid_passport.required' => "Enter {$who} NID or passport number.",
            'reference_mobile.regex' => 'Enter a valid mobile number (digits, spaces or dashes, optional +).',
        ];
    }
}
