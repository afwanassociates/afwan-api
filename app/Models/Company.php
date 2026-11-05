<?php

namespace App\Models;

use App\Support\Normalize;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company (employer) passports are entered for; its country is where the
 * employer is, not the passport's country. is_active and created_by are
 * deliberately not fillable: assign them explicitly.
 */
#[Fillable(['name', 'country_code', 'quota'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'quota' => 'integer',
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
     * Only companies that can be chosen for new entries.
     *
     * @param  Builder<Company>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Where the employer is. Countries are only ever deactivated, so this always resolves.
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code');
    }

    /**
     * Add has_any_passports (deleted passports included), for canBeDeleted().
     *
     * @param  Builder<Company>  $query
     */
    #[Scope]
    protected function withDeletable(Builder $query): void
    {
        $query->withExists(['passportEntries as has_any_passports' => fn (Builder $query) => $query->withTrashed()]);
    }

    /**
     * Only a company no passport has ever named (deleted ones included) may be deleted.
     */
    public function canBeDeleted(): bool
    {
        return ! (array_key_exists('has_any_passports', $this->attributes)
            ? (bool) $this->attributes['has_any_passports']
            : $this->passportEntries()->withTrashed()->exists());
    }

    /**
     * @return HasMany<PassportEntry, $this>
     */
    public function passportEntries(): HasMany
    {
        return $this->hasMany(PassportEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
