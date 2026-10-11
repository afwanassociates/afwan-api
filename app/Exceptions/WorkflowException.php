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
    public function __construct(public readonly string $reason, string $message)
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

    public static function laterStepExists(): self
    {
        return new self('later_step_exists', 'A later step already has records. Remove those records first.');
    }

    public static function orderViolation(string $stepLabel): self
    {
        return new self('order_violation', "This change would leave the {$stepLabel} step not completed while a later step has records.");
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
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], 422);
    }

    /**
     * Expected rule failures are not errors worth reporting.
     */
    public function report(): bool
    {
        return true;
    }
}
