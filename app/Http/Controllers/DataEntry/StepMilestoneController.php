<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DataEntry\Concerns\RespondsWithStepRecord;
use App\Http\Requests\DataEntry\MarkMilestoneRequest;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Services\WorkflowService;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Milestone steps (BMET Clearance): Training done and Finger done, in any
 * order, then BMET clearance done; each can be undone. Every change goes
 * through App\Services\WorkflowService.
 */
class StepMilestoneController extends Controller
{
    use RespondsWithStepRecord;

    public function __construct(protected WorkflowService $service, protected Workflow $workflow) {}

    /**
     * Mark the action done. The first milestone creates the step's record
     * (201); clearance needs every milestone.
     */
    public function store(MarkMilestoneRequest $request, PassportEntry $passport, string $step, string $action): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);
        $this->ensureAction($step, $action);

        $record = $this->service->markMilestone($passport, $step, $action, $request->actionDate(), $request->user());

        return $this->respond($record, $passport->refresh())->setStatusCode($record->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Undo the action. Undoing the last milestone removes the record, so the
     * response then has data null.
     */
    public function destroy(Request $request, PassportEntry $passport, string $step, string $action): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);
        $this->ensureAction($step, $action);

        $record = $this->service->undoMilestone($passport, $step, $action, $request->user());
        $passport->refresh();

        if ($record === null) {
            return response()->json(['data' => null, ...$this->passportPayload($passport)]);
        }

        return $this->respond($record, $passport);
    }

    /**
     * 404 for an action the step does not have.
     */
    protected function ensureAction(string $step, string $action): void
    {
        abort_unless(
            $this->workflow->milestone($step, $action) !== null || ($this->workflow->step($step)['complete_action']['key'] ?? null) === $action,
            404,
        );
    }
}
