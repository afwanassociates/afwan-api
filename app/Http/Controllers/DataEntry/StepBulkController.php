<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\BulkStepRequest;
use App\Models\PassportStepRecord;
use App\Services\StepBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StepBulkController extends Controller
{
    /**
     * Apply one action (visa: start / result; BMET: a milestone, the
     * clearance or all; flight: booking / confirm) to many passports. Each
     * passport is checked as its single button is; the ones that fail are
     * listed in `skipped` with the reason, the others in `updated`.
     */
    public function __invoke(BulkStepRequest $request, string $step, StepBulkService $bulk): JsonResponse
    {
        Gate::authorize('viewAny', PassportStepRecord::class);

        return response()->json($bulk->run(
            $step,
            $request->validated('action'),
            array_map('intval', $request->validated('passport_ids')),
            $request->validated('data', []),
            $request->user(),
        ));
    }
}
