<?php

namespace App\Models;

use App\Enums\EntryMode;
use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Support\LocalDate;
use App\Support\Normalize;
use Database\Factories\PassportEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
 * not fillable: they are set in code from the logged-in user. current_medical_id,
 * current_stage, stage_status and stage_status_date are not fillable either:
 * only App\Services\WorkflowService::recompute sets them. The medical slip
 * (medical_slip_date, medical_center_id) and the medical_date are set only
 * through App\Services\MedicalService, entry_mode only through
 * App\Services\WorkflowService::updateEntryMode, and the scan columns only through
 * App\Services\PassportScanService (scan_path is never sent to clients), and
 * current_return_id only through App\Services\PassportReturnService. entry_no,
 * the serial number (1, 2, 3, …), is given once when the entry is created and never
 * changes or comes back after a delete.
 */
#[Hidden(['scan_path'])]
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
            'entry_no' => 'integer',
            'date_of_birth' => 'date',
            'passport_received_date' => 'date',
            'passport_expiry_date' => 'date',
            'stage_status_date' => 'date',
            'medical_slip_date' => 'date',
            'medical_date' => 'date',
            'scan_size' => 'integer',
            'entry_mode' => EntryMode::class,
            'scan_uploaded_at' => 'datetime',
        ];
    }

    /**
     * Every new entry gets the next serial number, also when model events are
     * off (the seeders). The lock (inside the save's transaction) and the
     * unique index keep two entries saved at once apart.
     *
     * @param  Builder<static>  $query
     */
    protected function performInsert(Builder $query): bool
    {
        $this->entry_no ??= static::nextEntryNo();

        return parent::performInsert($query);
    }

    /**
     * The next serial number: one more than the highest ever given, deleted
     * entries included, so a number is never reused.
     */
    public static function nextEntryNo(): int
    {
        return (int) static::withTrashed()->lockForUpdate()->max('entry_no') + 1;
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
     * Where the medical is done (set with the medical slip). Includes
     * soft-deleted centres, so old entries still show theirs.
     *
     * @return BelongsTo<MedicalCenter, $this>
     */
    public function medicalCenter(): BelongsTo
    {
        return $this->belongsTo(MedicalCenter::class)->withTrashed();
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
     * The return in force (the passport was handed back to its owner), or null
     * while the passport is in the workflow.
     *
     * @return BelongsTo<PassportReturn, $this>
     */
    public function currentReturn(): BelongsTo
    {
        return $this->belongsTo(PassportReturn::class, 'current_return_id');
    }

    /**
     * Every return of this passport, undone ones included (history), oldest first.
     *
     * @return HasMany<PassportReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PassportReturn::class)->oldest('id');
    }

    /**
     * Whether the passport is returned to its owner (out of every working list).
     */
    public function isReturned(): bool
    {
        return $this->current_return_id !== null;
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
     * Every record of the steps after medical (all steps), newest first.
     *
     * @return HasMany<PassportStepRecord, $this>
     */
    public function stepRecords(): HasMany
    {
        return $this->hasMany(PassportStepRecord::class)->latest('step_date')->latest('id');
    }

    /**
     * Whether any step after medical has a record, read from the stored stage
     * (calling records exist exactly when the passport is past "calling,
     * waiting"), so no query is needed.
     */
    public function hasStepRecords(): bool
    {
        return ! in_array($this->current_stage, [null, 'passport', 'medical'], true)
            && ! ($this->current_stage === 'calling' && $this->stage_status === 'waiting');
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
        return MedicalStatus::fromRecord($this->currentMedical, LocalDate::today(), $this->medical_slip_date !== null);
    }

    /**
     * Whether a scan copy has been uploaded.
     */
    public function hasScan(): bool
    {
        return $this->scan_path !== null;
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
     * Leave out passports returned to their owners (every working list and step count).
     */
    #[Scope]
    protected function notReturned(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('current_return_id'));
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
