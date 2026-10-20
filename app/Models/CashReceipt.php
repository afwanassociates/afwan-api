<?php

namespace App\Models;

use App\Enums\CashReceiptType;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasApproval;
use Database\Factories\CashReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money received from a party or an owner. The payer's name, mobile and
 * address come from the party record. Create, edit and delete through
 * App\Services\CashReceiptService, which keeps the ledger line in step.
 */
#[Fillable(['date', 'party_id', 'receipt_type', 'amount', 'payment_method', 'bank_name', 'bank_branch', 'note'])]
class CashReceipt extends Model
{
    /** @use HasFactory<CashReceiptFactory> */
    use HasApproval, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'year' => 'integer',
            'sequence' => 'integer',
            'receipt_type' => CashReceiptType::class,
            'amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The party-ledger line this receipt wrote.
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
