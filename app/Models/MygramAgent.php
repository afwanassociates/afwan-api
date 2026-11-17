<?php

namespace App\Models;

use App\Support\Normalize;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner agent passports go to for the MYGRAM / medical slip. Not a party:
 * the company only pays agents (cash payments with payee_type "agent"), agents
 * never pay the company and have no rate. code, is_active and created_by are
 * not fillable: the code (AG-001) is given once on insert.
 */
#[Fillable(['name', 'rl_number', 'phone', 'address', 'notes'])]
class MygramAgent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MygramAgent $agent) {
            if ($agent->code === null) {
                $agent->assignCode();
            }
        });
    }

    /**
     * The next AG-### number. Save inside a transaction: the lock keeps two
     * agents saved at once from getting the same number.
     */
    public function assignCode(): void
    {
        $last = static::query()->lockForUpdate()->pluck('code')
            ->map(fn (string $code) => (int) preg_replace('/\D/', '', $code))
            ->max() ?? 0;

        $this->code = 'AG-'.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::name($value));
    }

    protected function rlNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtoupper(Normalize::name($value) ?? ''));
    }

    /**
     * Only agents that can get new passports.
     *
     * @param  Builder<MygramAgent>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * "Name (AG-001)", as shown on cash payments and in the cash book.
     */
    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Agents are never deleted once they have a passport or a payment.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->assignments()->exists() && ! $this->payments()->exists();
    }

    /**
     * @return HasMany<PassportAgentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PassportAgentAssignment::class);
    }

    /**
     * @return HasMany<CashPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CashPayment::class);
    }
}
