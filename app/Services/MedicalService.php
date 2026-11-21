<?php

namespace App\Services;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Exceptions\WorkflowException;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportActivity;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\User;
use App\Support\AgentGate;
use App\Support\DateRules;
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
     * medical slip date first. Once a result is recorded only the operations
     * head may change it, to start a re-test. Nothing else changes.
     *
     * @throws ValidationException when there is no slip date, or a result locks the date
     */
    public function updateMedicalDate(PassportEntry $passport, string $medicalDate, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($passport, $medicalDate, $actor) {
            $locked = $this->lock($passport);
            AgentGate::assertApproved($locked);

            if ($locked->medical_slip_date === null) {
                throw ValidationException::withMessages([
                    'medical_date' => 'Enter the medical slip date before the medical date.',
                ]);
            }

            if (! $actor->hasRole(...UserRole::passportManagers())
                && MedicalRecord::query()->where('passport_entry_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'medical_date' => 'The medical date cannot be changed once a medical result is recorded.',
                ]);
            }

            // Query builder update: only the medical date changes, not updated_at or updated_by.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update(['medical_date' => $medicalDate]);
            $passport->setAttribute('medical_date', $medicalDate)->syncOriginalAttribute('medical_date');
            PassportActivity::record($locked, 'medical', 'medical_date', $locked->medical_date ? 'Medical date changed' : 'Medical date added', [
                ['label' => 'Medical date', 'value' => $medicalDate, 'type' => 'date'],
            ], $actor);

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
            AgentGate::assertApproved($locked);

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
     * result is recorded; clearing it also clears the medical date and centre
     * (the passport is back on the Passport Entry list). Nothing else on the
     * passport changes. The medical date can be saved with it (medical_date):
     * locked once a result is recorded (the passport managers excepted, for a
     * re-test), never cleared once a result exists.
     *
     * @param  array{medical_slip_date: string|null, medical_center_id?: int|null, medical_date?: string|null}  $data
     *
     * @throws ValidationException when clearing the date after a result
     */
    public function updateSlip(PassportEntry $passport, array $data, ?User $actor = null): PassportEntry
    {
        return DB::transaction(function () use ($passport, $data, $actor) {
            $locked = $this->lock($passport);

            if ($data['medical_slip_date'] === null
                && MedicalRecord::query()->where('passport_entry_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'medical_slip_date' => 'The medical slip date cannot be cleared once a medical result is recorded.',
                ]);
            }

            // The first slip date needs an approved recruitment agent. Passports that already
            // had a slip before agents existed can still be edited.
            if ($data['medical_slip_date'] !== null && $locked->medical_slip_date === null) {
                $this->assertApprovedAgent($locked);
            }

            $values = array_intersect_key($data, array_flip(['medical_slip_date', 'medical_center_id', 'medical_date']));

            // The medical date (saved with the slip): only when it changes.
            if (array_key_exists('medical_date', $values)) {
                if ($values['medical_date'] === $locked->medical_date?->toDateString()) {
                    unset($values['medical_date']);
                } elseif (MedicalRecord::query()->where('passport_entry_id', $locked->id)->exists()
                    && ($values['medical_date'] === null || ! $actor?->hasRole(...UserRole::passportManagers()))) {
                    throw ValidationException::withMessages([
                        'medical_date' => 'The medical date cannot be changed once a medical result is recorded.',
                    ]);
                }
            }

            // Clearing the slip sends the passport back to the Passport Entry list as it was
            // before the slip: no medical date or centre is left behind.
            if ($data['medical_slip_date'] === null) {
                $values = [...$values, 'medical_center_id' => null, 'medical_date' => null];
            }

            // Query builder update: only the slip columns change, not updated_at or updated_by.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update($values);

            foreach ($values as $column => $value) {
                $passport->setAttribute($column, $value)->syncOriginalAttribute($column);
            }

            $this->logSlip($locked, $data, $actor);

            return $this->workflow->recompute($passport);
        });
    }

    /**
     * The slip in the passport history: added, edited or cleared (back to Passport Entry).
     * $locked holds the values before the change.
     *
     * @param  array<string, mixed>  $data
     */
    protected function logSlip(PassportEntry $locked, array $data, ?User $actor): void
    {
        if ($data['medical_slip_date'] === null) {
            PassportActivity::record($locked, 'medical', 'slip_clear', 'Medical slip cleared (back to Passport Entry)', [], $actor);

            return;
        }

        $centerId = array_key_exists('medical_center_id', $data) ? $data['medical_center_id'] : $locked->medical_center_id;
        $medicalDate = array_key_exists('medical_date', $data) ? $data['medical_date'] : $locked->medical_date?->toDateString();

        PassportActivity::record($locked, 'medical', $locked->medical_slip_date ? 'slip_edit' : 'slip', $locked->medical_slip_date ? 'Medical slip edited' : 'Medical slip added', [
            ['label' => 'Medical slip date', 'value' => $data['medical_slip_date'], 'type' => 'date'],
            ['label' => 'Medical date', 'value' => $medicalDate, 'type' => 'date'],
            ['label' => 'Medical center', 'value' => $centerId ? MedicalCenter::withTrashed()->find($centerId)?->name : null],
        ], $actor);
    }

    /**
     * The medical slip needs the passport's recruitment agent approved by the operations head.
     *
     * @throws ValidationException
     */
    protected function assertApprovedAgent(PassportEntry $passport): void
    {
        $assignments = PassportAgentAssignment::query()->where('passport_entry_id', $passport->id);

        if ((clone $assignments)->current()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'medical_slip_date' => (clone $assignments)->where('status', 'pending')->exists()
                ? 'Recruitment agent not approved yet. The Operations Head must approve it before the medical slip.'
                : 'Assign a recruitment agent first. The Operations Head must approve it before the medical slip.',
        ]);
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
            PassportActivity::record($passport, 'medical', 'medical_edit', 'Medical '.$record->result->label().' edited', [
                ['label' => 'Medical date', 'value' => $record->medical_date?->toDateString(), 'type' => 'date'],
                ['label' => 'Issue date', 'value' => $record->issue_date?->toDateString(), 'type' => 'date'],
                ['label' => 'Valid until', 'value' => $record->valid_until?->toDateString(), 'type' => 'date'],
            ], $actor);

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
            PassportActivity::record($passport, 'medical', 'medical_delete', 'Medical '.$record->result->label().' deleted', [], $actor);

            $this->workflow->ensureInOrder($passport);

            return $this->workflow->recompute($passport);
        });
    }

    /**
     * Cancel the passport's current medical result (the operations head): the record is
     * soft-deleted with who cancelled it (kept in the history, never removed) and the
     * Online mark is cleared, so the passport goes back to Medical → Pending (or to the
     * result before it, after a re-test). Refused once Calling has started.
     *
     * @throws ValidationException|WorkflowException
     */
    public function cancelResult(PassportEntry $passport, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($passport, $actor) {
            $locked = $this->lock($passport);

            if ($locked->hasStepRecords()) {
                throw ValidationException::withMessages([
                    'medical' => 'Calling has started for this passport, so its medical result can no longer be cancelled.',
                ]);
            }

            $record = $locked->current_medical_id
                ? MedicalRecord::query()->find($locked->current_medical_id)
                : null;
            if ($record === null) {
                throw ValidationException::withMessages([
                    'medical' => 'This passport has no medical result to cancel.',
                ]);
            }

            $record->deleted_by = $actor->id;
            $record->save();
            $record->delete();

            // The Online mark only follows a fit result: it goes with it.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update(['entry_mode' => null]);
            $passport->setAttribute('entry_mode', null)->syncOriginalAttribute('entry_mode');

            return $this->workflow->recompute($passport);
        });
    }

    /**
     * The usual expiry of a fit result: the date plus the "Fit result validity" date
     * rule (Settings → Date rules) of the company country $country; null for unfit.
     * Only a suggestion for prefilling forms and for sample data: a recorded
     * result keeps the expiry date entered for it.
     */
    public static function validUntil(string|DateTimeInterface $medicalDate, MedicalResult $result, ?string $country = null): ?string
    {
        if ($result !== MedicalResult::Fit) {
            return null;
        }

        return LocalDate::parse($medicalDate)
            ->addDays(app(DateRules::class)->get(DateRules::MEDICAL_VALIDITY_DAYS, $country))
            ->toDateString();
    }

    /**
     * Lock the passport row so concurrent writes for one passport run one at a time.
     * A returned passport takes no workflow changes until the return is undone.
     *
     * @throws WorkflowException
     */
    protected function lock(PassportEntry $passport): PassportEntry
    {
        $locked = PassportEntry::withTrashed()->lockForUpdate()->findOrFail($passport->id);

        if ($locked->current_return_id !== null) {
            throw WorkflowException::passportReturned();
        }

        return $locked;
    }
}
