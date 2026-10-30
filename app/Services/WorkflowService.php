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
     *   waiting (dated when the step before was completed). So with steps
     *   running side by side (BMET and flight) the stage is the earliest one
     *   not completed;
     * - every step completed: completed (when the last step was completed,
     *   e.g. the day the flight was confirmed).
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
     * need a fit current medical; a step may only have records when the steps
     * it starts after are completed (every earlier step, except that flight
     * runs alongside BMET), and may only be completed when every earlier step
     * is. Call inside the transaction of a change, so a violation rolls it
     * back.
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

        foreach ($this->workflow->recordStepKeys() as $key) {
            $record = $latest->get($key);

            if ($record === null) {
                continue;
            }

            $needed = $record->status === StepStatus::Completed
                ? $this->workflow->recordStepsBefore($key)
                : $this->workflow->stepsToStart($key);

            foreach ($needed as $earlier) {
                if ($latest->get($earlier)?->status !== StepStatus::Completed) {
                    throw WorkflowException::orderViolation($this->workflow->label($earlier));
                }
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
     * Complete a single-update step in one go (calling: the permit dates).
     * Creates the completed record, or completes an
     * older in-process one (its own date is kept in details under the step's
     * keep_date_as). A completed step can no longer change. The earlier steps
     * must be completed; the first step also needs a valid fit medical and,
     * for a new record, the Online mark (older records started before marks).
     *
     * @param  array<string, mixed>  $data  status, step_date, details, valid_until
     *
     * @throws WorkflowException
     */
    public function saveSingleUpdateStep(PassportEntry $passport, string $step, array $data, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $data, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);
            $open = $latest->get($step);
            $isFirst = $step === ($this->workflow->recordStepKeys()[0] ?? null);

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($this->workflow->label($step));
            }

            foreach ($this->workflow->recordStepsBefore($step) as $earlier) {
                if ($latest->get($earlier)?->status !== StepStatus::Completed) {
                    throw WorkflowException::previousStepIncomplete($this->workflow->label($earlier));
                }
            }

            if ($open?->status === StepStatus::InProcess) {
                if ($isFirst) {
                    $this->ensureMedicalForFirstStep($locked);
                }

                $keepAs = $this->workflow->step($step)['keep_date_as'] ?? null;

                if ($keepAs !== null) {
                    $data['details'] = [
                        ...($open->details ?? []),
                        ...($data['details'] ?? []),
                        $keepAs => $open->detail($keepAs) ?? $open->step_date->toDateString(),
                    ];
                }

                $record = $this->updateStep($open, $data, $actor);
            } else {
                if ($isFirst) {
                    $this->ensureReadyForFirstStep($locked);
                }

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
     * Save the booking date of a step that runs alongside the ones before it
     * (flight: allowed once the visa is issued, BMET cleared or not). Creates
     * the in-process record or changes its date; refused once confirmed.
     * The date goes into the step's date_from details key (booking_date).
     *
     * @throws WorkflowException
     */
    public function saveBooking(PassportEntry $passport, string $step, string $date, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $date, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);
            $open = $latest->get($step);
            $key = $this->bookingKey($step);

            $this->ensureCompleted($latest, $this->workflow->stepsToStart($step));

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($this->workflow->label($step));
            }

            if ($open !== null) {
                $record = $this->updateStep($open, ['step_date' => $date, 'details' => [...($open->details ?? []), $key => $date]], $actor);
            } else {
                $record = new PassportStepRecord(['status' => StepStatus::InProcess, 'step_date' => $date, 'details' => [$key => $date]]);
                $record->passport_entry_id = $locked->id;
                $record->step_key = $step;
                $record->created_by = $actor->id;
                $record->updated_by = $actor->id;
                $record->save();

                $this->ensureInOrder($locked);
            }

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * Clear the booking date: the in-process record is soft deleted (it stays
     * in the history), so the passport is Pending without a booking. Refused
     * once confirmed.
     *
     * @throws WorkflowException
     */
    public function clearBooking(PassportEntry $passport, string $step, User $actor): PassportEntry
    {
        return DB::transaction(function () use ($passport, $step, $actor) {
            $locked = $this->lock($passport);
            $open = $this->latestRecords($locked->id)->get($step);

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($this->workflow->label($step));
            }

            if ($open === null || $open->detail($this->bookingKey($step)) === null) {
                throw WorkflowException::bookingNotSet();
            }

            $open->deleted_by = $actor->id;
            $open->save();
            $open->delete();

            return $this->recompute($passport);
        });
    }

    /**
     * Confirm the step (flight): needs every earlier step completed (BMET
     * cleared) and a booking date. Completes the record, dated today in Dhaka
     * (kept in the result_date_from key, confirmed_date); the booking date
     * stays.
     *
     * @throws WorkflowException with the missing items (a step key, booking_date)
     */
    public function confirm(PassportEntry $passport, string $step, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);
            $open = $latest->get($step);

            $this->ensureCompleted($latest, $this->workflow->stepsToStart($step));

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($this->workflow->label($step));
            }

            $missing = [
                ...array_filter($this->workflow->parallelSteps($step), fn (string $key) => $latest->get($key)?->status !== StepStatus::Completed),
                ...($open?->detail($this->bookingKey($step)) === null ? [$this->bookingKey($step)] : []),
            ];

            if ($missing !== []) {
                $messages = $this->workflow->step($step)['confirm_messages'] ?? [];
                $message = count($missing) > 1 ? ($messages['both'] ?? null) : ($messages[$missing[0]] ?? null);

                throw WorkflowException::confirmRequirementsMissing($message ?? 'Complete the earlier steps before confirming.', array_values($missing));
            }

            $today = LocalDate::today()->toDateString();
            $confirmedKey = $this->workflow->step($step)['result_date_from'] ?? 'confirmed_date';

            $record = $this->updateStep($open, [
                'status' => StepStatus::Completed,
                'step_date' => $today,
                'details' => [...($open->details ?? []), $confirmedKey => $today],
            ], $actor);

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * Take back the confirmation: the record goes back to booked (in
     * process), dated by its booking date again, so the passport returns to
     * the step's Pending tab with its booking date.
     *
     * @throws WorkflowException when the step is not completed
     */
    public function undoConfirmation(PassportEntry $passport, string $step, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $actor) {
            $locked = $this->lock($passport);
            $record = $this->latestRecords($locked->id)->get($step);

            if ($record?->status !== StepStatus::Completed) {
                throw WorkflowException::notCompleted($this->workflow->label($step));
            }

            $details = $record->details ?? [];
            unset($details[$this->workflow->step($step)['result_date_from'] ?? 'confirmed_date']);
            $details[$this->bookingKey($step)] ??= $record->step_date->toDateString();

            $record = $this->updateStep($record, [
                'status' => StepStatus::InProcess,
                'step_date' => $details[$this->bookingKey($step)],
                'details' => $details,
            ], $actor);

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * The details key holding a step's booking date (its date_from).
     */
    protected function bookingKey(string $step): string
    {
        return (string) ($this->workflow->step($step)['date_from'] ?? 'booking_date');
    }

    /**
     * @param  Collection<string, PassportStepRecord>  $latest
     * @param  list<string>  $steps
     *
     * @throws WorkflowException naming the first step not completed
     */
    protected function ensureCompleted(Collection $latest, array $steps): void
    {
        foreach ($steps as $earlier) {
            if ($latest->get($earlier)?->status !== StepStatus::Completed) {
                throw WorkflowException::previousStepIncomplete($this->workflow->label($earlier));
            }
        }
    }

    /**
     * Mark a milestone step's action done on $date (BMET: training, finger
     * in any order, then clearance). The first milestone creates the
     * in-process record; each stores its date in its details field. The
     * complete action needs every milestone and completes the record, dated
     * $date (the date it was started is kept in details.start_date). The
     * earlier steps must be completed; a completed step can no longer change.
     *
     * @throws WorkflowException
     */
    public function markMilestone(PassportEntry $passport, string $step, string $action, string $date, User $actor): PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $action, $date, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);
            $open = $latest->get($step);
            $label = $this->workflow->label($step);

            foreach ($this->workflow->recordStepsBefore($step) as $earlier) {
                if ($latest->get($earlier)?->status !== StepStatus::Completed) {
                    throw WorkflowException::previousStepIncomplete($this->workflow->label($earlier));
                }
            }

            if ($open?->status === StepStatus::Completed) {
                throw WorkflowException::stepAlreadyCompleted($label);
            }

            $milestone = $this->workflow->milestone($step, $action);

            if ($milestone === null) {
                $missing = array_values(array_filter(
                    $this->workflow->step($step)['milestones'],
                    fn (array $milestone) => $open?->detail($milestone['field']) === null,
                ));

                if ($open === null || $missing !== []) {
                    throw WorkflowException::milestonesIncomplete($this->workflow->step($step)['complete_action']['label'], array_column($missing, 'label'));
                }

                $record = $this->updateStep($open, [
                    'status' => StepStatus::Completed,
                    'step_date' => $date,
                    'details' => [...($open->details ?? []), 'start_date' => $open->detail('start_date') ?? $open->step_date->toDateString()],
                ], $actor);
            } elseif ($open === null) {
                $record = new PassportStepRecord([
                    'status' => StepStatus::InProcess,
                    'step_date' => $date,
                    'details' => [$milestone['field'] => $date],
                ]);
                $record->passport_entry_id = $locked->id;
                $record->step_key = $step;
                $record->created_by = $actor->id;
                $record->updated_by = $actor->id;
                $record->save();
            } else {
                // Marking again changes the date; the record is dated by its earliest milestone.
                $record = $this->updateStep($open, [
                    'step_date' => min($open->step_date->toDateString(), $date),
                    'details' => [...($open->details ?? []), $milestone['field'] => $date],
                ], $actor);
            }

            $this->recompute($passport);

            return $record;
        });
    }

    /**
     * Undo a milestone step's action. A milestone cannot be undone once the
     * step is completed; undoing the last one removes the in-process record
     * (soft deleted, so it stays in the history). Undoing the complete action
     * puts the record back in process with its start date, refused while
     * the next step is completed.
     *
     * @throws WorkflowException
     */
    public function undoMilestone(PassportEntry $passport, string $step, string $action, User $actor): ?PassportStepRecord
    {
        return DB::transaction(function () use ($passport, $step, $action, $actor) {
            $locked = $this->lock($passport);
            $latest = $this->latestRecords($locked->id);
            $open = $latest->get($step);
            $label = $this->workflow->label($step);
            $milestone = $this->workflow->milestone($step, $action);

            if ($milestone === null) {
                if ($open?->status !== StepStatus::Completed) {
                    throw WorkflowException::notCompleted($label);
                }

                $next = $this->workflow->recordStepsAfter($step)[0] ?? null;

                if ($next !== null && $latest->get($next)?->status === StepStatus::Completed) {
                    throw WorkflowException::laterStepCompleted($this->workflow->label($next));
                }

                $details = $open->details ?? [];
                $startDate = $details['start_date'] ?? $open->step_date->toDateString();
                unset($details['start_date']);

                $record = $this->updateStep($open, ['status' => StepStatus::InProcess, 'step_date' => $startDate, 'details' => $details], $actor);
            } else {
                if ($open?->status === StepStatus::Completed) {
                    throw WorkflowException::milestoneLocked($milestone['label'], $label);
                }

                if ($open?->detail($milestone['field']) === null) {
                    throw WorkflowException::milestoneNotMarked($milestone['label']);
                }

                $details = $open->details ?? [];
                unset($details[$milestone['field']]);
                $remaining = array_filter(array_map(fn (array $other) => $details[$other['field']] ?? null, $this->workflow->step($step)['milestones']));

                if ($remaining === []) {
                    $open->deleted_by = $actor->id;
                    $open->save();
                    $open->delete();
                    $record = null;
                } else {
                    $record = $this->updateStep($open, ['details' => $details], $actor);
                }
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

        return [Workflow::COMPLETED, null, $previousDate];
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
