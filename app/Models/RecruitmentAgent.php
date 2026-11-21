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
 * A recruitment agent that processes passports (medical, calling, visa, BMET).
 * Partner agents are paid by the company (cash payments with payee_type
 * "agent"), never the other way, and have no rate. One agent can be our own
 * company (is_own): it goes through the same steps and is never paid. Like any
 * agent it can be deactivated.
 *
 * Not a party. code, is_own, is_active and created_by are not fillable: the
 * code (AG-001) is given once on insert.
 */
#[Fillable(['name', 'rl_number', 'phone', 'address', 'notes'])]
class RecruitmentAgent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_own' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RecruitmentAgent $agent) {
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
     * @param  Builder<RecruitmentAgent>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Our own company first, then partners by code (AG-002, AG-003, …).
     *
     * @param  Builder<RecruitmentAgent>  $query
     */
    #[Scope]
    protected function ownFirst(Builder $query): void
    {
        $query->orderByDesc('is_own')->orderBy('code');
    }

    /**
     * Partner agents only (the ones the company pays).
     *
     * @param  Builder<RecruitmentAgent>  $query
     */
    #[Scope]
    protected function partners(Builder $query): void
    {
        $query->where('is_own', false);
    }

    /**
     * "Name (AG-001)", as shown on cash payments and in the cash book.
     */
    public function label(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Passports still in process with this partner agent: its current (approved) or
     * pending assignment, not returned to the owner and flight not done yet. A partner
     * agent with any cannot be deactivated.
     */
    public function passportsStillWithAgent(): int
    {
        return PassportAgentAssignment::query()
            ->where('recruitment_agent_id', $this->id)
            ->where(fn (Builder $q) => $q->current()->orWhere('status', 'pending'))
            ->whereHas('passportEntry', fn (Builder $q) => $q
                ->notReturned()
                ->where('current_stage', '<>', \App\Support\Workflow::COMPLETED))
            ->distinct()
            ->count('passport_entry_id');
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
