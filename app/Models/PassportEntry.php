<?php

namespace App\Models;

use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Support\LocalDate;
use App\Support\Normalize;
use Database\Factories\PassportEntryFactory;
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
 * A received passport. created_by, updated_by and deleted_by are deliberately
 * not fillable: they are set in code from the logged-in user. current_medical_id
 * and stage_status_date are not fillable either: only
 * App\Services\WorkflowService::recompute sets them.
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
     * Table alias of the current medical record joined by withCurrentMedical().
     */
    public const CURRENT_MEDICAL = 'current_medical';

    /**
     * Fields that old entries may lack. Missing any of them makes the entry
     * incomplete, which flags the passport step as needing attention.
     *
     * @var list<string>
     */
    public const COMPLETENESS_FIELDS = ['country_code', 'date_of_birth', 'passport_expiry_date'];

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
            'stage_status_date' => 'date',
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

    /**
     * Every medical record of this passport (history), newest first.
     *
     * @return HasMany<MedicalRecord, $this>
     */
    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class)->latest('medical_date')->latest('id');
    }

    /**
     * The latest non-deleted medical record, or null while the medical is pending.
     *
     * @return BelongsTo<MedicalRecord, $this>
     */
    public function currentMedical(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'current_medical_id');
    }

    /**
     * The medical status today (load currentMedical first to avoid a query).
     */
    public function medicalStatus(): MedicalStatus
    {
        return MedicalStatus::fromRecord($this->currentMedical, LocalDate::today());
    }

    /**
     * Whether any field old entries may lack is missing.
     */
    public function isIncomplete(): bool
    {
        foreach (self::COMPLETENESS_FIELDS as $field) {
            if ($this->getAttribute($field) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Left join the current medical record as "current_medical", keeping only
     * passport_entries columns in the result. Safe to call more than once.
     */
    #[Scope]
    protected function withCurrentMedical(Builder $query): void
    {
        $alias = self::CURRENT_MEDICAL;
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if ($join->table === "medical_records as {$alias}") {
                return;
            }
        }

        $query->leftJoin("medical_records as {$alias}", "{$alias}.id", '=', $this->qualifyColumn('current_medical_id'))
            ->select($this->qualifyColumn('*'));
    }

    /**
     * Only passports in the given medical status today.
     */
    #[Scope]
    protected function whereMedicalStatus(Builder $query, MedicalStatus $status): void
    {
        $query->withCurrentMedical();

        $status->applyTo($query->getQuery(), self::CURRENT_MEDICAL, LocalDate::today());
    }

    /**
     * Leave out unfit passports (the default for the main passport list).
     */
    #[Scope]
    protected function withoutUnfit(Builder $query): void
    {
        $alias = self::CURRENT_MEDICAL;

        $query->withCurrentMedical()->where(fn (Builder $query) => $query
            ->whereNull("{$alias}.id")
            ->orWhere("{$alias}.result", '<>', MedicalResult::Unfit->value));
    }
}
