<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\BalanceType;
use App\Enums\PartyTransactionType;
use App\Enums\PartyType;
use App\Support\Normalize;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A party (client) or an owner of the company, with an opening balance and a
 * ledger. type, sequence, code, is_active and created_by are deliberately not
 * fillable: the code is assigned once on insert and never changes.
 */
#[Fillable(['name', 'mobile', 'address', 'nid_passport', 'tin_bin', 'opening_balance', 'opening_balance_type'])]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartyType::class,
            'sequence' => 'integer',
            'opening_balance' => 'decimal:2',
            'opening_balance_type' => BalanceType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Trim and collapse repeated spaces.
     */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::name($value));
    }

    /**
     * A new party gets the next number of its type just before it is inserted.
     */
    protected static function booted(): void
    {
        static::creating(function (Party $party) {
            if ($party->code === null) {
                $party->assignCode();
            }
        });
    }

    /**
     * Give a new party the next number of its type. Save inside a transaction:
     * the lock makes two parties saved at once get different numbers.
     */
    public function assignCode(): void
    {
        $last = (int) static::query()->where('type', $this->type)->lockForUpdate()->max('sequence');

        $this->sequence = $last + 1;
        $this->code = $this->type->code($this->sequence);
    }

    /**
     * The opening balance with its side: positive when the party owes us.
     */
    public function signedOpeningBalance(): float
    {
        $amount = (float) $this->opening_balance;

        return $this->opening_balance_type === BalanceType::Payable ? -$amount : $amount;
    }

    /**
     * Only parties that can be chosen for new transactions.
     *
     * @param  Builder<Party>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Add the ledger totals of approved lines per line type as <type>_total
     * columns (receivable_total, payment_total, …).
     *
     * @param  Builder<Party>  $query
     */
    #[Scope]
    protected function withTotals(Builder $query): void
    {
        foreach (PartyTransactionType::cases() as $type) {
            $query->withSum(
                ["transactions as {$type->value}_total" => fn (Builder $q) => $q->where('type', $type->value)->where('status', ApprovalStatus::Approved->value)],
                'amount',
            );
        }
    }

    /**
     * @return HasMany<PartyTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PartyTransaction::class);
    }

    /**
     * @return HasMany<CashReceipt, $this>
     */
    public function cashReceipts(): HasMany
    {
        return $this->hasMany(CashReceipt::class);
    }

    /**
     * @return HasMany<CashPayment, $this>
     */
    public function cashPayments(): HasMany
    {
        return $this->hasMany(CashPayment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
