<?php

namespace App\Services;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Exceptions\WorkflowException;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Support\LocalDate;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates, changes or deletes medical records, and that
 * sets a passport's medical slip and medical date. Every
 * write runs in a transaction that locks the passport row, derives
 * valid_until, records who acted, then has WorkflowService check that later
 * steps still have the fit medical they need (otherwise the change is rolled
 * back with a 422) and recompute the passport's stage.
 */
class MedicalService
{
    public function __construct(protected WorkflowService $workflow) {}

    /**
     * Set the date the passport goes (or went) for its medical. Needs the
     * medical slip date first. Once a result is recorded only admin and
     * super_admin may change it, to start a re-test. Nothing else changes.
     *
     * @throws ValidationException when there is no slip date, or a result locks the date
     */
    public function updateMedicalDate(PassportEntry $passport, string $medicalDate, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($passport, $medicalDate, $actor) {
            $locked = $this->lock($passport);

            if ($locked->medical_slip_date === null) {
                throw ValidationException::withMessages([
                    'medical_date' => 'Enter the medical slip date before the medical date.',
                ]);
            }

            if (! $actor->hasRole(UserRole::Admin, UserRole::SuperAdmin)
                && MedicalRecord::query()->where('passport_entry_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'medical_date' => 'The medical date cannot be changed once a medical result is recorded.',
                ]);
            }

            // Query builder update: only the medical date changes, not updated_at or updated_by.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update(['medical_date' => $medicalDate]);
            $passport->setAttribute('medical_date', $medicalDate)->syncOriginalAttribute('medical_date');

            return $passport;
        });
    }

    /**
     * Record the medical result for the passport's medical date. Old records
     * are kept as history; a re-test needs a new medical date first, so one
     * medical date never gets two results.
     *
     * @throws ValidationException when the slip date or medical date is missing, or already has a result
     * @throws WorkflowException when later steps would lose their fit medical
     */
    public function recordResult(PassportEntry $passport, MedicalResult $result, User $actor, ?string $issueDate = null, ?string $expiryDate = null): MedicalRecord
    {
        return DB::transaction(function () use ($passport, $result, $actor, $issueDate, $expiryDate) {
            // A fit result carries its own issue and expiry dates (not computed).
            if ($result === MedicalResult::Fit && ($issueDate === null || $expiryDate === null)) {
                throw ValidationException::withMessages(array_filter([
                    'issue_date' => $issueDate === null ? 'Enter the issue date of the fit medical.' : null,
                    'expiry_date' => $expiryDate === null ? 'Enter the expiry date of the fit medical.' : null,
                ]));
            }

            $locked = $this->lock($passport);

            // A result follows the medical slip (MYGRAM) and the medical date.
            if ($locked->medical_slip_date === null) {
                throw ValidationException::withMessages([
                    'medical_slip_date' => 'Enter the medical slip date before recording the medical result.',
                ]);
            }

            if ($locked->medical_date === null) {
                throw ValidationException::withMessages([
                    'medical_date' => 'Enter the medical date before recording the medical result.',
                ]);
            }

            $alreadyRecorded = MedicalRecord::query()
                ->where('passport_entry_id', $locked->id)
                ->whereDate('medical_date', $locked->medical_date)
                ->exists();

            if ($alreadyRecorded) {
                throw ValidationException::withMessages([
                    'medical_date' => 'A result is already recorded for this medical date. Set a new medical date for a re-test.',
                ]);
            }

            $record = new MedicalRecord(['medical_date' => $locked->medical_date->toDateString(), 'result' => $result]);
            $record->passport_entry_id = $locked->id;
            $record->issue_date = $result === MedicalResult::Fit ? $issueDate : null;
            $record->valid_until = $result === MedicalResult::Fit ? $expiryDate : null;
            $record->created_by = $actor->id;
            $record->updated_by = $actor->id;
            $record->save();

            $this->workflow->ensureInOrder($locked);
            $this->workflow->recompute($passport);

            return $record;
        });
    }

    /**
     * Save the passport's medical slip (the client's "MYGRAM"): its date and
     * medical centre. Entering the date moves the passport from
     * not_started to pending. The date can be cleared only while no medical
     * result is recorded. Nothing else on the passport changes.
     *
     * @param  array{medical_slip_date: string|null, medical_center_id?: int|null}  $data
     *
     * @throws ValidationException when clearing the date after a result
     */
    public function updateSlip(PassportEntry $passport, array $data): PassportEntry
    {
        return DB::transaction(function () use ($passport, $data) {
            $locked = $this->lock($passport);

            if ($data['medical_slip_date'] === null
                && MedicalRecord::query()->where('passport_entry_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'medical_slip_date' => 'The medical slip date cannot be cleared once a medical result is recorded.',
                ]);
            }

            $values = array_intersect_key($data, array_flip(['medical_slip_date', 'medical_center_id']));

            // Query builder update: only the slip columns change, not updated_at or updated_by.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update($values);

            foreach ($values as $column => $value) {
                $passport->setAttribute($column, $value)->syncOriginalAttribute($column);
            }

            return $this->workflow->recompute($passport);
        });
    }

    /**
     * Change a medical record's date, result or remarks.
     *
     * @param  array{medical_date?: string, result?: string|MedicalResult, remarks?: string|null}  $data
     */
    public function update(MedicalRecord $record, array $data, User $actor): MedicalRecord
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $passport = $this->lock($record->passportEntry()->withTrashed()->firstOrFail());

            $record->fill(collect($data)->except(['issue_date', 'expiry_date'])->all());

            // A fit result's dates are entered, never computed; unfit has none.
            if (array_key_exists('issue_date', $data)) {
                $record->issue_date = $data['issue_date'];
            }

            if (array_key_exists('expiry_date', $data)) {
                $record->valid_until = $data['expiry_date'];
            }

            if ($record->result === MedicalResult::Unfit) {
                $record->issue_date = null;
                $record->valid_until = null;
            } elseif ($record->valid_until === null) {
                throw ValidationException::withMessages([
                    'expiry_date' => 'Enter the expiry date of the fit medical.',
                ]);
            }

            $record->updated_by = $actor->id;
            $record->save();

            $this->workflow->ensureInOrder($passport);
            $this->workflow->recompute($passport);

            // Correcting the current result's date corrects the passport's too.
            if ($record->wasChanged('medical_date') && $passport->current_medical_id === $record->id) {
                PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update(['medical_date' => $record->medical_date->toDateString()]);
            }

            return $record;
        });
    }

    /**
     * Soft delete a medical record. If it was the current one, the passport
     * falls back to the previous latest record (or pending).
     */
    public function delete(MedicalRecord $record, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($record, $actor) {
            $passport = $this->lock($record->passportEntry()->withTrashed()->firstOrFail());

            $record->deleted_by = $actor->id;
            $record->save();
            $record->delete();

            $this->workflow->ensureInOrder($passport);

            return $this->workflow->recompute($passport);
        });
    }

    /**
     * The usual expiry of a fit result: the date plus config
     * medical.validity_months (month ends never overflow); null for unfit.
     * Only a suggestion for prefilling forms and for sample data: a recorded
     * result keeps the expiry date entered for it.
     */
    public static function validUntil(string|DateTimeInterface $medicalDate, MedicalResult $result): ?string
    {
        if ($result !== MedicalResult::Fit) {
            return null;
        }

        return LocalDate::parse($medicalDate)
            ->addMonthsNoOverflow((int) config('medical.validity_months'))
            ->toDateString();
    }

    /**
     * Lock the passport row so concurrent writes for one passport run one at a time.
     */
    protected function lock(PassportEntry $passport): PassportEntry
    {
        return PassportEntry::withTrashed()->lockForUpdate()->findOrFail($passport->id);
    }
}
