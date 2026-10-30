<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A workflow rule stopped a change (e.g. a step recorded out of order). Shown
 * to the SPA as a 422 with a machine-readable code. Messages never contain
 * passport numbers, dates of birth or other personal data.
 */
class WorkflowException extends Exception
{
    /**
     * @param  array<string, mixed>  $context  extra machine-readable fields for the response
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function previousStepIncomplete(string $stepLabel): self
    {
        return new self('previous_step_incomplete', "Complete the {$stepLabel} step first.");
    }

    public static function medicalNotValid(): self
    {
        return new self('medical_not_valid', 'Calling can only be recorded while the medical is fit and still valid today.');
    }

    public static function entryModeMissing(string $stepLabel): self
    {
        return new self('entry_mode_missing', "Mark the passport Online before starting {$stepLabel}.");
    }

    public static function stepAlreadyCompleted(string $stepLabel): self
    {
        return new self('step_already_completed', "The {$stepLabel} step is already completed.");
    }

    public static function stepInProcess(string $stepLabel): self
    {
        return new self('step_in_process', "The {$stepLabel} step is in process: update its record instead of adding a new one.");
    }

    public static function stepNotStarted(string $stepLabel): self
    {
        return new self('step_not_started', "The {$stepLabel} step has no record in process: start it before recording its result.");
    }

    public static function notCompleted(string $stepLabel): self
    {
        return new self('step_not_completed', "The {$stepLabel} step is not completed, so there is nothing to undo.");
    }

    /**
     * @param  list<string>  $missing  the milestone labels still to do
     */
    public static function milestonesIncomplete(string $actionLabel, array $missing): self
    {
        return new self('milestones_incomplete', 'Mark '.implode(' and ', $missing)." before {$actionLabel}.");
    }

    public static function milestoneNotMarked(string $milestoneLabel): self
    {
        return new self('milestone_not_marked', "{$milestoneLabel} is not marked, so there is nothing to undo.");
    }

    public static function milestoneLocked(string $milestoneLabel, string $stepLabel): self
    {
        return new self('milestone_locked', "{$milestoneLabel} cannot be undone once the {$stepLabel} step is completed.");
    }

    public static function laterStepCompleted(string $stepLabel): self
    {
        return new self('later_step_completed', "Undo the {$stepLabel} step first.");
    }

    /**
     * @param  list<string>  $missing  e.g. ["bmet", "booking_date"]
     */
    public static function confirmRequirementsMissing(string $message, array $missing): self
    {
        return new self('confirm_requirements_missing', $message, ['missing' => $missing]);
    }

    public static function bookingNotSet(): self
    {
        return new self('booking_not_set', 'There is no flight booking date to clear.');
    }

    public static function laterStepExists(): self
    {
        return new self('later_step_exists', 'A later step already has records. Remove those records first.');
    }

    public static function orderViolation(string $stepLabel): self
    {
        return new self('order_violation', "This change would leave the {$stepLabel} step not completed while a later step has records.");
    }

    public static function passportReturned(): self
    {
        return new self('passport_returned', 'This passport was returned to its owner. Undo the return before changing its steps.');
    }

    public static function alreadyReturned(): self
    {
        return new self('already_returned', 'This passport is already returned.');
    }

    public static function flightConfirmed(): self
    {
        return new self('flight_confirmed', 'A passport cannot be returned once its flight is confirmed.');
    }

    public static function notReturned(): self
    {
        return new self('not_returned', 'This passport is not returned, so there is nothing to undo.');
    }

    public static function laterStepsNeedFitMedical(): self
    {
        return new self('later_steps_exist', 'This passport already has calling or later step records, which need a fit medical. Remove those records first.');
    }

    /**
     * Render the exception as a 422 JSON response.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason, ...$this->context], 422);
    }

    /**
     * Expected rule failures are not errors worth reporting.
     */
    public function report(): bool
    {
        return true;
    }
}
