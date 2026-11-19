<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Undo Calling or Visa (the operations head): see WorkflowService::undoStep.
 * BMET and Flight have their own undos (StepMilestoneController, StepActionController).
 */
class StepUndoController extends Controller
{
    public function __invoke(Request $request, PassportEntry $passport, string $step, WorkflowService $workflow): PassportEntryResource
    {
        Gate::authorize('undoStep', $passport);

        $workflow->undoStep($passport, $step, $request->user());

        return new PassportEntryResource($passport->refresh()->load(PassportEntryResource::RELATIONS));
    }
}
