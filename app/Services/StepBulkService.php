<?php

namespace App\Services;

use App\Enums\StepStatus;
use App\Exceptions\WorkflowException;
use App\Http\Requests\DataEntry\BookFlightRequest;
use App\Http\Requests\DataEntry\MarkMilestoneRequest;
use App\Http\Requests\DataEntry\StartStepRequest;
use App\Http\Requests\DataEntry\StepResultRequest;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Support\Workflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bulk updates on the step pages: one action for many passports. Each
 * passport goes through exactly what its single button does: the same form
 * request (its rules for that passport) and the same WorkflowService method,
 * so the step order, dates and permissions are checked the same way.
 *
 * A passport that fails is skipped with the reason; the others are saved
 * (partial success). Each passport is saved on its own.
 */
class StepBulkService
{
    /** Actions on a step that is started, then given a result (visa). */
    public const START_RESULT_ACTIONS = ['start', 'result'];

    /** Actions on the flight step. */
    public const FLIGHT_ACTIONS = ['booking', 'confirm'];

    /** BMET: every milestone at once, then the clearance. */
    public const ALL_MILESTONES = 'all';

    public function __construct(protected WorkflowService $service, protected Workflow $workflow) {}

    /**
     * The actions a step offers in bulk.
     *
     * @return list<string>
     */
    public function actionsFor(string $step): array
    {
        return match (true) {
            $this->workflow->hasMilestones($step) => [
                ...array_column($this->workflow->step($step)['milestones'], 'key'),
                $this->workflow->step($step)['complete_action']['key'],
                self::ALL_MILESTONES,
            ],
            $step === 'flight' => self::FLIGHT_ACTIONS,
            in_array($step, $this->workflow->startResultStepKeys(), true) => self::START_RESULT_ACTIONS,
            default => [],
        };
    }

    /**
     * Run the action for every passport, in the order given.
     *
     * @param  list<int>  $passportIds
     * @param  array<string, mixed>  $data  the body the single action takes
     * @return array{updated: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function run(string $step, string $action, array $passportIds, array $data, User $user): array
    {
        $passports = PassportEntry::query()->whereIn('id', $passportIds)->get()->keyBy('id');
        $updated = [];
        $skipped = [];

        foreach ($passportIds as $id) {
            $passport = $passports->get($id);

            if ($passport === null) {
                continue;
            }

            $row = ['id' => $passport->id, 'passport_number' => $passport->passport_number, 'passport_name' => $passport->passport_name];

            try {
                Gate::forUser($user)->authorize('create', [PassportStepRecord::class, $passport]);

                $result = DB::transaction(fn () => $this->apply($passport, $step, $action, $data, $user));
                $passport->refresh();

                $updated[] = [
                    ...$row,
                    ...$result,
                    'warnings' => array_column($this->workflow->warnings($passport->load(['currentMedical', 'stepRecords'])), 'message'),
                ];
            } catch (ValidationException $e) {
                $skipped[] = [...$row, 'reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            } catch (WorkflowException $e) {
                $skipped[] = [...$row, 'reason' => $e->getMessage()];
            } catch (AuthorizationException) {
                $skipped[] = [...$row, 'reason' => 'You are not allowed to change this passport.'];
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * One passport. Returns what an admin needs to undo it: the new record's
     * id (start), the actions marked (BMET) or the previous booking date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function apply(PassportEntry $passport, string $step, string $action, array $data, User $user): array
    {
        if ($this->workflow->hasMilestones($step)) {
            return ['marked' => $this->milestones($passport, $step, $action, $data, $user)];
        }

        return match ($action) {
            'start' => ['record_id' => $this->start($passport, $step, $data, $user)->id],
            'result' => ['record_id' => $this->result($passport, $step, $data, $user)->id],
            'booking' => $this->booking($passport, $step, $data, $user),
            'confirm' => ['record_id' => $this->service->confirm($passport, $step, $user)->id],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function start(PassportEntry $passport, string $step, array $data, User $user): PassportStepRecord
    {
        /** @var StartStepRequest $form */
        $form = $this->validated(StartStepRequest::class, $passport, ['step' => $step], $data, $user);

        return $this->service->recordStep($passport, $step, $form->recordData(), $user);
    }

    /**
     * As StepActionController::result: the start date is kept in details.
     *
     * @param  array<string, mixed>  $data
     */
    protected function result(PassportEntry $passport, string $step, array $data, User $user): PassportStepRecord
    {
        /** @var StepResultRequest $form */
        $form = $this->validated(StepResultRequest::class, $passport, ['step' => $step], $data, $user);
        $record = $form->record();
        $values = $form->recordData();

        $values['details'] = [
            ...($values['details'] ?? []),
            'start_date' => $record->detail('start_date') ?? $record->step_date->toDateString(),
        ];

        return $this->service->updateStep($record, $values, $user);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record_id: int, previous_booking_date: string|null}
     */
    protected function booking(PassportEntry $passport, string $step, array $data, User $user): array
    {
        $form = $this->validated(BookFlightRequest::class, $passport, [], $data, $user);
        $current = $this->workflow->latestRecords($passport->load('stepRecords'))->get($step);
        $previous = $current?->status === StepStatus::InProcess ? $current->detail('booking_date') : null;

        $record = $this->service->saveBooking($passport, $step, $form->validated('booking_date'), $user);

        return ['record_id' => $record->id, 'previous_booking_date' => $previous];
    }

    /**
     * Mark one milestone (skipped when already done), the complete action,
     * or every milestone not yet done and then the complete action.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> the actions marked, in order
     */
    protected function milestones(PassportEntry $passport, string $step, string $action, array $data, User $user): array
    {
        $complete = $this->workflow->step($step)['complete_action']['key'];
        $milestones = array_column($this->workflow->step($step)['milestones'], 'key');
        $actions = $action === self::ALL_MILESTONES ? [...$milestones, $complete] : [$action];
        $marked = [];

        foreach ($actions as $key) {
            $milestone = $this->workflow->milestone($step, $key);
            $record = $this->workflow->latestRecords($passport->load('stepRecords'))->get($step);
            $done = $milestone !== null && $record?->status !== StepStatus::Completed ? $record?->detail($milestone['field']) : null;

            if ($done !== null) {
                // Already marked: "All done" moves on; a single action is skipped.
                if ($action === self::ALL_MILESTONES) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'date' => "{$milestone['label']} already on ".date('d-m-Y', strtotime($done)).'.',
                ]);
            }

            /** @var MarkMilestoneRequest $form */
            $form = $this->validated(MarkMilestoneRequest::class, $passport, ['step' => $step, 'action' => $key], $data, $user);
            $this->service->markMilestone($passport, $step, $key, $form->actionDate(), $user);
            $passport->refresh();
            $marked[] = $key;
        }

        return $marked;
    }

    /**
     * Validate the body as the single action's form request does, for this
     * passport (its route parameters bound, the user set).
     *
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $data
     */
    protected function validated(string $class, PassportEntry $passport, array $parameters, array $data, User $user): FormRequest
    {
        $form = $class::create('/', 'POST', $data);
        $form->setContainer(app())->setRedirector(app('redirect'));

        $route = new Route(['POST'], '/', []);
        $route->parameters = ['passport' => $passport, ...$parameters];
        $form->setRouteResolver(fn () => $route);
        $form->setUserResolver(fn () => $user);

        $form->validateResolved();

        return $form;
    }
}
