<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DataEntry\Concerns\RespondsWithStepRecord;
use App\Http\Requests\DataEntry\StoreStepRecordRequest;
use App\Http\Requests\DataEntry\UpdateStepRecordRequest;
use App\Http\Resources\PassportStepRecordResource;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Services\WorkflowService;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class StepRecordController extends Controller
{
    use RespondsWithStepRecord;

    public function __construct(protected WorkflowService $service, protected Workflow $workflow) {}

    /**
     * A passport's step history, grouped by step (every record step, in
     * order), newest first within each.
     */
    public function index(PassportEntry $passport): JsonResponse
    {
        Gate::authorize('viewAny', PassportStepRecord::class);

        $records = $passport->stepRecords()->with(PassportStepRecordResource::RELATIONS)->get();
        $records->each->setRelation('passportEntry', $passport);

        $grouped = [];

        foreach ($this->workflow->recordStepKeys() as $key) {
            $grouped[$key] = PassportStepRecordResource::collection($records->where('step_key', $key)->values())->resolve(request());
        }

        return response()->json(['data' => $grouped]);
    }

    /**
     * Record a step (a rejected step may be recorded again; history is kept).
     */
    public function store(StoreStepRecordRequest $request, PassportEntry $passport, string $stepKey): JsonResponse
    {
        Gate::authorize('create', [PassportStepRecord::class, $passport]);

        $record = $this->service->recordStep($passport, $stepKey, $request->recordData(), $request->user());

        return $this->respond($record, $passport)->setStatusCode(201);
    }

    /**
     * Change a step record, e.g. move in_process to completed or rejected.
     */
    public function update(UpdateStepRecordRequest $request, PassportStepRecord $stepRecord): JsonResponse
    {
        Gate::authorize('update', $stepRecord);

        $record = $this->service->updateStep($stepRecord, $request->recordData(), $request->user());

        return $this->respond($record, $record->passportEntry()->withTrashed()->firstOrFail());
    }

    /**
     * Soft delete a step record, recording who deleted it.
     */
    public function destroy(Request $request, PassportStepRecord $stepRecord): Response
    {
        Gate::authorize('delete', $stepRecord);

        $this->service->deleteStep($stepRecord, $request->user());

        return response()->noContent();
    }
}
