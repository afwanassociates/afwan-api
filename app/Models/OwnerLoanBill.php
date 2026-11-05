<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\HasApproval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company bill an owner paid with money the company lent them (an owner
 * loan), with a copy of the bill. Once approved it settles that much of the
 * loan; no money moves, so cash in hand does not change. Written only through
 * App\Services\OwnerLoans, which keeps its ledger line in step.
 */
#[Fillable(['date', 'expense_category', 'purpose', 'bill_no', 'amount', 'note'])]
class OwnerLoanBill extends Model
{
    use HasApproval;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'expense_category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'copy_size' => 'integer',
        ];
    }

    /**
     * The owner loan (a cash payment) this bill settles.
     *
     * @return BelongsTo<CashPayment, $this>
     */
    public function ownerLoan(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class, 'owner_loan_id');
    }

    /**
     * The line this bill wrote in the owner's ledger.
     *
     * @return HasOne<PartyTransaction, $this>
     */
    public function ledgerLine(): HasOne
    {
        return $this->hasOne(PartyTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
