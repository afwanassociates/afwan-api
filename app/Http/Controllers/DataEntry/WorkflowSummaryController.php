<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\PassportEntry;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WorkflowSummaryController extends Controller
{
    /**
     * Counts for the step bar, computed live from two aggregate queries.
     */
    public function __invoke(Workflow $workflow): JsonResponse
    {
        Gate::authorize('viewAny', PassportEntry::class);

        return response()->json(['data' => [
            'steps' => $workflow->stepList(),
            ...$workflow->summary(),
        ]]);
    }
}
