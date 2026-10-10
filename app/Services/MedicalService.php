<?php

namespace App\Services;

use App\Enums\MedicalResult;
use App\Exceptions\WorkflowException;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Support\LocalDate;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates, changes or deletes medical records. Every
 * write runs in a transaction that locks the passport row, derives
 * valid_until, records who acted, then has WorkflowService check that later
 * steps still have the fit medical they need (otherwise the change is rolled
 * back with a 422) and recompute the passport's stage.
 */
class MedicalService
{
    public function __construct(protected WorkflowService $workflow) {}

    /**
     * Record a new medical result. Old records are kept as history.
     *
     *
     * @param  array{medical_date: string, result: string|MedicalResult, remarks?: string|null}  $data
     *
     * @throws ValidationException when the medical slip date is not entered yet
     * @throws WorkflowException when later steps would lose their fit medical
     */
    public function record(PassportEntry $passport, array $data, User $actor): MedicalRecord
    {
        return DB::transaction(function () use ($passport, $data, $actor) {
            $locked = $this->lock($passport);

            // A result follows the medical slip (MYGRAM): the slip date comes first.
            if ($locked->medical_slip_date === null) {
                throw ValidationException::withMessages([
                    'medical_slip_date' => 'Enter the medical slip date before recording the medical result.',
                ]);
            }

            $record = new MedicalRecord($data);
            $record->passport_entry_id = $locked->id;
            $record->valid_until = self::validUntil($record->medical_date, $record->result);
            $record->created_by = $actor->id;
            $record->updated_by = $actor->id;
            $record->save();

            $this->workflow->ensureInOrder($locked);
            $this->workflow->recompute($passport);

            return $record;
        });
    }

    /**
     * Save the passport's medical slip (the client's "MYGRAM"): its date,
     * number and medical centre. Entering the date moves the passport from
     * not_started to pending. The date can be cleared only while no medical
     * result is recorded. Nothing else on the passport changes.
     *
     * @param  array{medical_slip_date: string|null, medical_slip_no?: string|null, medical_center_id?: int|null}  $data
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

            $values = array_intersect_key($data, array_flip(['medical_slip_date', 'medical_slip_no', 'medical_center_id']));

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

            $record->fill($data);

            // Only re-derive when the inputs change, so a later change to the
            // validity period does not silently move old records.
            if ($record->isDirty(['medical_date', 'result'])) {
                $record->valid_until = self::validUntil($record->medical_date, $record->result);
            }

            $record->updated_by = $actor->id;
            $record->save();

            $this->workflow->ensureInOrder($passport);
            $this->workflow->recompute($passport);

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
     * The date a result stays valid until: medical date plus the validity
     * period for fit (month ends never overflow), null for unfit.
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
