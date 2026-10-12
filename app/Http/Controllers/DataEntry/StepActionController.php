<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DataEntry\Concerns\RespondsWithStepRecord;
use App\Http\Requests\DataEntry\StartStepRequest;
use App\Http\Requests\DataEntry\StepResultRequest;
use App\Http\Requests\DataEntry\UpdateCallingRequest;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Services\WorkflowService;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The step pages' two actions: start a step (a new in_process record) and
 * record its result. Both go through App\Services\WorkflowService, which
 * enforces the step order and recomputes the passport's stage.
 */
class StepActionController extends Controller
{
    use RespondsWithStepRecord;

    public function __construct(protected WorkflowService $service, protected Workflow $workflow) {}

    /**
     * Start the step: the earlier steps must be completed (and, for the first
     * step, the medical fit and valid today, and the passport marked Online).
     * A rejected step may be started again; history is kept.
     */
    public function start(StartStepRequest $request, PassportEntry $passport, string $step): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);

        $record = $this->service->recordStep($passport, $step, $request->recordData(), $request->user());

        return $this->respond($record, $passport)->setStatusCode(201);
    }

    /**
     * Calling / Work Permit in one update: the receive, issued and expiry
     * dates make it Permitted (Visa becomes available). Needs a valid fit
     * medical and the Online mark; a permitted step can no longer change.
     */
    public function updateCalling(UpdateCallingRequest $request, PassportEntry $passport): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);

        $record = $this->service->saveFirstStep($passport, $request->recordData(), $request->user());

        return $this->respond($record, $passport->refresh())->setStatusCode($record->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Record the step's result on its in_process record. The result date
     * becomes the record's date; the date it was started is kept in
     * details.start_date.
     */
    public function result(StepResultRequest $request, PassportEntry $passport, string $step): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);

        $record = $request->record();
        $data = $request->recordData();

        $data['details'] = [
            ...($data['details'] ?? []),
            'start_date' => $record->detail('start_date') ?? $record->step_date->toDateString(),
        ];

        $record = $this->service->updateStep($record, $data, $request->user());

        return $this->respond($record, $passport->refresh());
    }
}
