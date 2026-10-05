<?php

namespace App\Models;

use App\Support\Normalize;
use Database\Factories\PassportEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A received passport. created_by, updated_by and deleted_by are deliberately
 * not fillable: they are set in code from the logged-in user.
 */
#[Fillable([
    'passport_name', 'passport_number', 'country_code', 'date_of_birth', 'reference_id', 'company_id',
    'passport_received_date', 'passport_expiry_date',
])]
class PassportEntry extends Model
{
    /** @use HasFactory<PassportEntryFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'passport_received_date' => 'date',
            'passport_expiry_date' => 'date',
        ];
    }

    /**
     * Stored uppercase without any spaces.
     */
    protected function passportNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::passportNumber($value));
    }

    /**
     * Stored uppercase with single spaces.
     */
    protected function passportName(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Normalize::passportName($value));
    }

    /**
     * The passport's issuing country (nullable on old entries).
     *
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code');
    }

    /**
     * Includes soft-deleted reference records, so old entries still show theirs.
     *
     * @return BelongsTo<Reference, $this>
     */
    public function reference(): BelongsTo
    {
        return $this->belongsTo(Reference::class)->withTrashed();
    }

    /**
     * Includes soft-deleted company records, so old entries still show theirs.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
