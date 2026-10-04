<?php

namespace App\Models;

use App\Enums\ReferenceType;
use App\Support\Normalize;
use Database\Factories\ReferenceFactory;
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
 * A person or agency who refers passports. is_active and created_by are
 * deliberately not fillable: assign them explicitly.
 */
#[Fillable(['type', 'name', 'phone', 'notes'])]
class Reference extends Model
{
    /** @use HasFactory<ReferenceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReferenceType::class,
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
     * Only references that can be chosen for new entries.
     *
     * @param  Builder<Reference>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
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
