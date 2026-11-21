<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A country, keyed by its ISO 3166-1 alpha-2 code. Countries are never
 * deleted, only deactivated, so old records always show their name.
 * is_active and updated_by are deliberately not fillable.
 */
#[Fillable(['code', 'name', 'is_pinned', 'sort_order'])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_pinned' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Stored uppercase without spaces.
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : Str::upper(trim($value)));
    }

    /**
     * Trim and collapse repeated spaces.
     */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : Str::squish($value));
    }

    /**
     * Countries that can be chosen for new records.
     *
     * @param  Builder<Country>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Pinned countries first (by sort_order, then name), then the rest by name.
     *
     * @param  Builder<Country>  $query
     */
    #[Scope]
    protected function dropdownOrder(Builder $query): void
    {
        $query->orderByDesc('is_pinned')
            ->orderByRaw('case when is_pinned then sort_order else 0 end')
            ->orderBy('name');
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'country_code');
    }

    /**
     * @return HasMany<PassportEntry, $this>
     */
    public function passportEntries(): HasMany
    {
        return $this->hasMany(PassportEntry::class, 'country_code');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
