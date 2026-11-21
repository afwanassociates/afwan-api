<?php

namespace App\Http\Requests\Accounts;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Optional date range of a party ledger or the cash book (inclusive); for the
 * cash book also which book: cash in hand (default), bank, mobile banking or all.
 */
class LedgerRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'book' => ['sometimes', 'nullable', Rule::in([...PaymentMethod::values(), 'all'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
