<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\PassportEntry;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class WorkflowConfigController extends Controller
{
    /**
     * The workflow's step definitions (labels, status options, form fields),
     * so the SPA can build its step forms from config/workflow.php.
     */
    public function __invoke(Workflow $workflow): JsonResponse
    {
        Gate::authorize('viewAny', PassportEntry::class);

        return response()->json(['data' => ['steps' => $workflow->definitions()]]);
    }
}
