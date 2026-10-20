<?php

namespace App\Models;

use App\Enums\CashPaymentType;
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
#[Fillable(['date', 'payee_type', 'party_id', 'payee_name', 'payment_type', 'purpose', 'amount', 'payment_method', 'bank_name', 'bank_branch', 'note'])]
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
