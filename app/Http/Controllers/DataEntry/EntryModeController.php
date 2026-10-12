<?php

namespace App\Http\Controllers\DataEntry;

use App\Enums\EntryMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\UpdateEntryModeRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Gate;

class EntryModeController extends Controller
{
    /**
     * Mark a fit passport Online (or clear it), making it available
     * for Calling / Work Permit. Refused once Calling has started.
     */
    public function __invoke(UpdateEntryModeRequest $request, PassportEntry $passport, WorkflowService $workflow): PassportEntryResource
    {
        Gate::authorize('updateEntryMode', $passport);

        $mode = $request->validated('entry_mode');
        $workflow->updateEntryMode($passport, $mode === null ? null : EntryMode::from($mode));

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }
}
