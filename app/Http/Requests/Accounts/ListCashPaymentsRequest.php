<?php

namespace App\Http\Requests\Accounts;

use App\Enums\ApprovalStatus;
use App\Enums\CashPaymentType;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCashPaymentsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Payment no, party ID or name, payee name or purpose.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'party_id' => ['sometimes', 'nullable', 'integer'],
            'payee_type' => ['sometimes', 'nullable', Rule::in(PayeeType::values())],
            'payment_type' => ['sometimes', 'nullable', Rule::in(CashPaymentType::values())],
            'payment_method' => ['sometimes', 'nullable', Rule::in(PaymentMethod::values())],
            'status' => ['sometimes', 'nullable', Rule::in(ApprovalStatus::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
