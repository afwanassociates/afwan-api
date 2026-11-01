<?php

namespace App\Models;

use App\Enums\CashPaymentType;
use App\Enums\ExpenseCategory;
use App\Enums\MobileBankingProvider;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasApproval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money paid to a party, an owner or another payee. A party/owner payee's
 * details come from the party record; another payee is typed (payee_name,
 * purpose). Write through App\Services\CashPaymentService, which keeps the
 * ledger line in step and the cash in hand from going negative.
 */
#[Fillable(['date', 'payee_type', 'party_id', 'payee_name', 'payment_type', 'loan_receipt_id', 'expense_category', 'purpose', 'bill_no', 'amount', 'payment_method', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number', 'mobile_provider', 'mobile_number', 'note'])]
class CashPayment extends Model
{
    use HasApproval;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'year' => 'integer',
            'sequence' => 'integer',
            'payee_type' => PayeeType::class,
            'payment_type' => CashPaymentType::class,
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'mobile_provider' => MobileBankingProvider::class,
            'expense_category' => ExpenseCategory::class,
        ];
    }

    /**
     * The payee's name: the party's, or the typed one.
     */
    public function payeeName(): string
    {
        return $this->party?->name ?? (string) $this->payee_name;
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasOne<PartyTransaction, $this>
     */
    public function ledgerLine(): HasOne
    {
        return $this->hasOne(PartyTransaction::class);
    }

    /**
     * What the payment was for, in one line: "Payment to owner", or for an
     * expense "Office rent · October rent · Bill no. 123".
     */
    public function summary(): string
    {
        return implode(' · ', array_filter([
            $this->expense_category?->label() ?? $this->payment_type->label(),
            $this->purpose,
            $this->bill_no ? 'Bill no. '.$this->bill_no : null,
        ]));
    }

    /**
     * The owner loan (a loan cash receipt) a loan repayment pays back.
     *
     * @return BelongsTo<CashReceipt, $this>
     */
    public function loanReceipt(): BelongsTo
    {
        return $this->belongsTo(CashReceipt::class, 'loan_receipt_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
