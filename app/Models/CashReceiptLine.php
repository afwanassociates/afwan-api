<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One company on a party's cash receipt: the amount received for it and the
 * passports it pays, as a full payment (they are paid off) or a part payment
 * (more receipts may pay them). Written only by App\Services\CashReceiptService.
 */
#[Fillable(['company_id', 'amount', 'is_full_payment'])]
class CashReceiptLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_full_payment' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CashReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CashReceipt::class, 'cash_receipt_id');
    }

    /**
     * Deleted companies included: the receipt still names them.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * The passports this line pays (deleted ones included).
     *
     * @return BelongsToMany<PassportEntry, $this>
     */
    public function passports(): BelongsToMany
    {
        return $this->belongsToMany(PassportEntry::class, 'cash_receipt_passports')
            ->withPivot('cash_receipt_id')
            ->withTimestamps()
            ->withTrashed()
            ->orderBy('entry_no');
    }
}
