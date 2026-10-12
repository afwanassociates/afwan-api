<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Enums\StepStatus;
use App\Exceptions\WorkflowException;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place that creates, changes or deletes step records (calling,
 * visa, bmet, flight) and that writes a passport's stored workflow position:
 * current_medical_id, current_stage, stage_status and stage_status_date.
 *
 * The stored position depends only on records, never on today's date, so it
 * does not go stale. Every write runs in a transaction that locks the
 * passport row and checks the steps are still in order before committing.
 */
class WorkflowService
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * Work out and store where the passport is in the workflow:
     * - no current medical and no medical slip date: passport / not_started
     *   (dated by the received date);
     * - no current medical but a slip date: medical / pending (the slip date);
     * - unfit: medical / unfit (the medical date);
     * - fit, not marked Online and no calling record yet: medical /
     *   fit (dated by the medical date);
     * - fit (even if its validity has ended): walk the record steps in order
     *   by their latest record. Completed moves on; in_process or rejected
     *   stops there (that record's step date); no record stops there as
     *   waiting (dated when the step before was completed);
     * - every step completed: completed (the flight's departure date).
     */
    public function recompute(PassportEntry $passport): PassportEntry
    {
        $medical = $this->currentMedical($passport->id);
        [$stage, $status, $date] = $this->position($passport, $medical, $this->latestRecords($passport->id));

        $values = [
            'current_medical_id' => $medical?->id,
            'current_stage' => $stage,
            'stage_status' => $status,
            'stage_status_date' => $date,
        ];

        $changed = array_filter(
            $values,
            fn (int|string|null $value, string $column) => $this->stored($passport, $column) !== ($value === null ? null : (string) $value),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed !== []) {
            // Query builder update: a workflow change is not an edit of the
            // passport, so its updated_at and updated_by stay as they are.
            PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update($changed);
        }

        foreach ($values as $column => $value) {
            $passport->setAttribute($column, $value)->syncOriginalAttribute($column);
        }
        $passport->unsetRelation('currentMedical')->unsetRelation('stepRecords');

        return $passport;
    }

    /**
     * Recompute every passport, soft-deleted ones included, in chunks (used to
     * backfill after a migration and by workflow:recompute-stages).
     */
    public function recomputeAll(int $chunk = 500): int
    {
        $count = 0;

        // Every column: a migration may call this before later columns exist.
        PassportEntry::withTrashed()
            ->chunkById($chunk, function (Collection $passports) use (&$count) {
                foreach ($passports as $passport) {
                    $this->recompute($passport);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Record a step. Allowed only when every earlier step is completed (and,
     * for the first step after medical, the medical is fit and valid today),
     * and the step has no record yet or its latest record was rejected.
     *
     * @param  array<string, mixed>  $data  status, step_date, reference_no, valid_until, details, remarks
     *
     * @throws WorkflowException
     */
    public function recordStep(PassportEntry $passport, string $stepKey, array $data, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $stepKey, $data, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);

            $this->ensureCanRecord($locked, $stepKey, $latest);

            $record = new PassportStepRecord($data);
            $record->passport_entry_id = $locked->id;
            $record->step_key = $stepKey;
            $record->created_by = $actor->id;
            $record->updated_by = $actor->id;
            $record->save();

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * Change a step record (e.g. move in_process to completed or rejected).
     * Fails if the steps would end up out of order.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WorkflowException
     */
    public function updateStep(PassportStepRecord $record, array $data, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $passport = $this->lock($record->passportEntry()->withTrashed()->firstOrFail());

            $record->fill($data);
            $record->updated_by = $actor->id;
            $record->save();

            $this->ensureInOrder($passport);
            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * Mark a fit passport Online (or clear the mark), which makes it
     * available for the first step after medical. Marking needs a fit medical
     * still valid today; neither marking nor clearing is allowed once that
     * step has a record.
     *
     * @throws ValidationException
     */
    public function updateEntryMode(PassportEntry $passport, ?EntryMode $mode): PassportEntry
    {
        return DB::transaction(function () use ($passport, $mode) {
            $locked = $this->lock($passport);
            $first = $this->workflow->recordStepKeys()[0] ?? null;

            if (PassportStepRecord::query()->where('passport_entry_id', $locked->id)->where('step_key', $first)->exists()) {
                throw ValidationException::withMessages([
                    'entry_mode' => 'The Online mark cannot be changed once '.$this->workflow->label((string) $first).' has started.',
                ]);
            }

            if ($mode !== null && ! MedicalStatus::fromRecord($this->currentMedical($locked->id), LocalDate::today())->isPassed()) {
                throw ValidationException::withMessages([
                    'entry_mode' => 'Only a passport with a fit medical that has not expired can be marked Online.',
                ]);
            }

            // Query builder update: the mark is not an edit of the passport details.
            PassportEntry::withTrashed()->whereKey($locked->id)->toBase()->update(['entry_mode' => $mode?->value]);
            $passport->setAttribute('entry_mode', $mode)->syncOriginalAttribute('entry_mode');

            return $this->recompute($passport);
        });
    }

    /**
     * Soft delete a step record, allowed only while no later step has records.
     * The step's previous latest record (if any) becomes current again.
     *
     * @throws WorkflowException
     */
    public function deleteStep(PassportStepRecord $record, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($record, $actor) {
            $passport = $this->lock($record->passportEntry()->withTrashed()->firstOrFail());

            $hasLater = PassportStepRecord::query()
                ->where('passport_entry_id', $passport->id)
                ->whereIn('step_key', $this->workflow->recordStepsAfter($record->step_key))
                ->exists();

            if ($hasLater) {
                throw WorkflowException::laterStepExists();
            }

            $record->deleted_by = $actor->id;
            $record->save();
            $record->delete();

            return $this->recompute($passport);
        });
    }

    /**
     * Check the passport's records are still in order: records after medical
     * need a fit current medical, and a step may only have records when every
     * earlier step's latest record is completed. Call inside the transaction
     * of a change, so a violation rolls it back.
     *
     * @throws WorkflowException
     */
    public function ensureInOrder(PassportEntry $passport): void
    {
        $latest = $this->latestRecords($passport->id);

        if ($latest->isEmpty()) {
            return;
        }

        if ($this->currentMedical($passport->id)?->result !== MedicalResult::Fit) {
            throw WorkflowException::laterStepsNeedFitMedical();
        }

        $open = null;

        foreach ($this->workflow->recordStepKeys() as $key) {
            if ($open !== null && $latest->has($key)) {
                throw WorkflowException::orderViolation($this->workflow->label($open));
            }

            if ($open === null && $latest->get($key)?->status !== StepStatus::Completed) {
                $open = $key;
            }
        }
    }

    /**
     * @param  Collection<string, PassportStepRecord>  $latest
     *
     * @throws WorkflowException
     */
    protected function ensureCanRecord(PassportEntry $passport, string $stepKey, Collection $latest): void
    {
        $label = $this->workflow->label($stepKey);

        if ($stepKey === ($this->workflow->recordStepKeys()[0] ?? null)) {
            $this->ensureReadyForFirstStep($passport);
        }

        foreach ($this->workflow->recordStepsBefore($stepKey) as $earlier) {
            if ($latest->get($earlier)?->status !== StepStatus::Completed) {
                throw WorkflowException::previousStepIncomplete($this->workflow->label($earlier));
            }
        }

        match ($latest->get($stepKey)?->status) {
            StepStatus::Completed => throw WorkflowException::stepAlreadyCompleted($label),
            StepStatus::InProcess => throw WorkflowException::stepInProcess($label),
            default => null,
        };
    }

    /**
     * The first step after medical needs a fit medical that is valid today,
     * and the passport marked Online.
     *
     * @throws WorkflowException
     */
    public function ensureReadyForFirstStep(PassportEntry $passport): void
    {
        $this->ensureMedicalForFirstStep($passport);

        if ($passport->entry_mode === null) {
            throw WorkflowException::entryModeMissing($this->workflow->label((string) ($this->workflow->recordStepKeys()[0] ?? '')));
        }
    }

    /**
     * The first step after medical needs a fit medical that is valid today.
     *
     * @throws WorkflowException
     */
    public function ensureMedicalForFirstStep(PassportEntry $passport): void
    {
        if (MedicalStatus::fromRecord($this->currentMedical($passport->id), LocalDate::today())->isPassed() === false) {
            throw WorkflowException::medicalNotValid();
        }
    }

    /**
     * Complete the first step after medical in one go (calling: the permit
     * dates). Creates the completed record, or completes an older in-process
     * one (its calling date is kept in details.calling_date). A completed step
     * can no longer change. A new record needs the passport marked Online; an
     * older in-process one started before marks existed.
     *
     * @param  array<string, mixed>  $data  status, step_date, details, valid_until
     *
     * @throws WorkflowException
     */
    public function saveFirstStep(PassportEntry $passport, array $data, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $data, $actor) {
            $locked = $this->lock($passport);
            $step = (string) ($this->workflow->recordStepKeys()[0] ?? '');
            $open = $this->latestRecords($locked->id)->get($step);

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($this->workflow->label($step));
            }

            if ($open?->status === StepStatus::InProcess) {
                $this->ensureMedicalForFirstStep($locked);

                $data['details'] = [
                    ...($data['details'] ?? []),
                    'calling_date' => $open->detail('calling_date') ?? $open->step_date->toDateString(),
                ];
                $record = $this->updateStep($open, $data, $actor);
            } else {
                $this->ensureReadyForFirstStep($locked);

                $record = new PassportStepRecord($data);
                $record->passport_entry_id = $locked->id;
                $record->step_key = $step;
                $record->created_by = $actor->id;
                $record->updated_by = $actor->id;
                $record->save();
            }

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * [stage, status, date] for the passport. See recompute().
     *
     * @param  Collection<string, PassportStepRecord>  $latest
     * @return array{string, string|null, string|null}
     */
    protected function position(PassportEntry $passport, ?MedicalRecord $medical, Collection $latest): array
    {
        if ($medical === null) {
            return $passport->medical_slip_date !== null
                ? ['medical', 'pending', $passport->medical_slip_date->toDateString()]
                : [Workflow::PASSPORT, 'not_started', $passport->passport_received_date?->toDateString()];
        }

        if ($medical->result === MedicalResult::Unfit) {
            return ['medical', 'unfit', $medical->medical_date->toDateString()];
        }

        $previousDate = $medical->medical_date->toDateString();
        $last = null;
        $first = $this->workflow->recordStepKeys()[0] ?? null;

        // Fit, but not yet marked Online, and Calling not started:
        // it stays in the Medical Fit tab.
        if ($first !== null && ! $latest->has($first) && $passport->entry_mode === null) {
            return ['medical', 'fit', $previousDate];
        }

        foreach ($this->workflow->recordStepKeys() as $key) {
            $record = $latest->get($key);

            if ($record === null) {
                return [$key, 'waiting', $previousDate];
            }

            if ($record->status !== StepStatus::Completed) {
                return [$key, $record->status->value, $record->step_date->toDateString()];
            }

            $previousDate = $this->workflow->completedOn($record);
            $last = $record;
        }

        return [
            Workflow::COMPLETED,
            null,
            $this->workflow->departureDate($last)?->toDateString() ?? $previousDate,
        ];
    }

    /**
     * The latest non-deleted medical record (by medical_date, ties by id).
     */
    protected function currentMedical(int $passportId): ?MedicalRecord
    {
        return MedicalRecord::query()
            ->where('passport_entry_id', $passportId)
            ->orderByDesc('medical_date')
            ->orderByDesc('id')
            ->first(['id', 'medical_date', 'result', 'valid_until']);
    }

    /**
     * The latest non-deleted record of each step (by step_date, ties by id).
     *
     * @return Collection<string, PassportStepRecord>
     */
    protected function latestRecords(int $passportId): Collection
    {
        // Only records whose status their step has (a rejected calling record
        // is history only).
        return PassportStepRecord::query()
            ->where('passport_entry_id', $passportId)
            ->orderByDesc('step_date')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PassportStepRecord $record) => $this->workflow->isActiveRecord($record))
            ->unique('step_key')
            ->keyBy('step_key');
    }

    /**
     * Lock the passport row so concurrent writes for one passport run one at a time.
     */
    protected function lock(PassportEntry $passport): PassportEntry
    {
        return PassportEntry::withTrashed()->lockForUpdate()->findOrFail($passport->id);
    }

    /**
     * The column's stored value as a string, for comparison.
     */
    protected function stored(PassportEntry $passport, string $column): ?string
    {
        $value = $passport->getAttribute($column);

        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
