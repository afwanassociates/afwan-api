<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\BalanceType;
use App\Enums\OwnerDesignation;
use App\Enums\PartyKind;
use App\Enums\PartyTransactionType;
use App\Enums\PartyType;
use App\Support\Like;
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
 * ledger. A client is an agency or a person (kind) and is also the reference
 * of the passports it gives. type, sequence, code, is_active and created_by
 * are deliberately not fillable: the code is assigned once on insert and
 * never changes.
 */
#[Fillable(['kind', 'rl_number', 'name', 'agency_owner_name', 'designation', 'mobile', 'address', 'nid_passport', 'tin_bin', 'notes', 'opening_balance', 'opening_balance_type'])]
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
            'kind' => PartyKind::class,
            'designation' => OwnerDesignation::class,
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
     * Only an agency keeps an RL number and an agency owner; owners have no kind.
     */
    protected static function booted(): void
    {
        static::creating(function (Party $party) {
            if ($party->code === null) {
                $party->assignCode();
            }
        });

        static::saving(function (Party $party) {
            if ($party->type === PartyType::Owner) {
                $party->kind = null;
            }

            if ($party->kind === PartyKind::Person) {
                $party->rl_number = null;
                $party->agency_owner_name = null;
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
     * Clients only (agencies and persons): the parties passports can name as
     * their reference.
     *
     * @param  Builder<Party>  $query
     */
    #[Scope]
    protected function clients(Builder $query): void
    {
        $query->where('type', PartyType::Client->value);
    }

    /**
     * Match a reference search: name, phone, party ID or RL number.
     *
     * @param  Builder<Party>  $query
     */
    #[Scope]
    protected function referenceSearch(Builder $query, string $search): void
    {
        $pattern = Like::contains($search);

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', $pattern)
            ->orWhere('mobile', 'like', $pattern)
            ->orWhere('code', 'like', $pattern)
            ->orWhere('rl_number', 'like', $pattern));
    }

    /**
     * Add the has_* flags canBeDeleted() reads: passports (deleted ones
     * included), cash receipts, cash payments and ledger lines.
     *
     * @param  Builder<Party>  $query
     */
    #[Scope]
    protected function withDeletable(Builder $query): void
    {
        $query->withExists(self::deleteBlockers());
    }

    /**
     * The relations that keep a party from being deleted, as has_* flags.
     *
     * @return array<string, \Closure>
     */
    public static function deleteBlockers(): array
    {
        return [
            'passportEntries as has_any_passports' => fn (Builder $query) => $query->withTrashed(),
            'cashReceipts as has_cash_receipts' => fn (Builder $query) => $query,
            'cashPayments as has_cash_payments' => fn (Builder $query) => $query,
            'transactions as has_transactions' => fn (Builder $query) => $query,
        ];
    }

    /**
     * Only a party or owner that no passport has ever named (deleted ones
     * included) and that has nothing in accounts (no receipts, payments,
     * ledger lines or opening balance; owner loans are receipts) may be deleted.
     */
    public function canBeDeleted(): bool
    {
        if ((float) $this->opening_balance !== 0.0) {
            return false;
        }

        foreach (self::deleteBlockers() as $relation => $constraint) {
            [$name, $flag] = explode(' as ', $relation);
            if (array_key_exists($flag, $this->attributes)) {
                $exists = (bool) $this->attributes[$flag];
            } else {
                $exists = $constraint($this->{$name}()->getQuery())->exists();
            }

            if ($exists) {
                return false;
            }
        }

        return true;
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
     * The passports this party gave (it is their reference).
     *
     * @return HasMany<PassportEntry, $this>
     */
    public function passportEntries(): HasMany
    {
        return $this->hasMany(PassportEntry::class, 'reference_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
