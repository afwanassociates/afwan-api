<?php

namespace App\Http\Requests\Accounts\Concerns;

use App\Enums\MobileBankingProvider;
use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

/**
 * The mobile banking fields of a cash receipt or payment: the service
 * (bKash, Nagad…) and the 11-digit wallet number, required when the
 * payment method is mobile banking.
 */
trait ValidatesMobileBanking
{
    /**
     * Wallet numbers are typed with spaces or dashes, sometimes with +88.
     */
    protected function normalizeMobileNumber(): void
    {
        if (is_string($this->input('mobile_number'))) {
            $digits = preg_replace('/\D+/', '', $this->input('mobile_number'));
            $this->merge(['mobile_number' => preg_replace('/^880?(?=1)/', '0', $digits) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function mobileBankingRules(): array
    {
        $required = 'required_if:payment_method,'.PaymentMethod::MobileBanking->value;

        return [
            'mobile_provider' => ['nullable', $required, Rule::in(MobileBankingProvider::values())],
            'mobile_number' => ['nullable', $required, 'string', 'regex:/^01[3-9]\d{8}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mobileBankingMessages(): array
    {
        return [
            'payment_method.required' => 'Choose how the money was '.$this->moneyVerb().'.',
            'mobile_provider.required_if' => 'Choose the mobile banking service.',
            'mobile_provider.in' => 'Choose the mobile banking service.',
            'mobile_number.required_if' => 'Enter the mobile banking number.',
            'mobile_number.regex' => 'Enter an 11-digit mobile number, e.g. 01712345678.',
        ];
    }

    /**
     * "received" or "paid", for the messages.
     */
    abstract protected function moneyVerb(): string;
}
