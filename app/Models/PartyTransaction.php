<?php

namespace App\Models;

use App\Enums\PartyTransactionType;
use App\Models\Concerns\HasApproval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a party ledger. Lines with a cash_receipt_id or cash_payment_id
 * belong to that voucher and change only through it (App\Services\CashReceiptService,
 * App\Services\CashPaymentService).
 */
#[Fillable(['date', 'type', 'amount', 'note'])]
class PartyTransaction extends Model
{
    use HasApproval;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'type' => PartyTransactionType::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Whether the line was entered by hand on the ledger (not from a receipt or payment).
     */
    public function isManual(): bool
    {
        return $this->cash_receipt_id === null && $this->cash_payment_id === null;
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<CashReceipt, $this>
     */
    public function cashReceipt(): BelongsTo
    {
        return $this->belongsTo(CashReceipt::class);
    }

    /**
     * @return BelongsTo<CashPayment, $this>
     */
    public function cashPayment(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
