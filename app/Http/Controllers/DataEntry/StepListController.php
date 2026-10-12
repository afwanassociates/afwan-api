<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListStepPassportsRequest;
use App\Http\Resources\StepListResource;
use App\Models\PassportStepRecord;
use App\Support\StepList;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StepListController extends Controller
{
    /**
     * One step's work list by tab (pending, in_process, completed, rejected),
     * in the tab's fixed order. Readable by accounts too.
     */
    public function __invoke(ListStepPassportsRequest $request, string $step, StepList $list): AnonymousResourceCollection
    {
        Gate::authorize('viewList', PassportStepRecord::class);

        $page = $list->query($step, $request->validated('tab') ?? 'pending', $request->validated())
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $rows = StepListResource::collection($page);
        $rows->collection->each->forStep($step);

        // Each tab's total with the same search, for the tab badges (the same
        // tab definition as the rows and /workflow/summary).
        return $rows->additional(['tab_counts' => $list->tabCounts($step, $request->validated())]);
    }
}
