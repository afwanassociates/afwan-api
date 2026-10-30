<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListStepPassportsRequest;
use App\Http\Resources\StepListResource;
use App\Models\PassportStepRecord;
use App\Support\StepList;
use App\Support\StepListCsv;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StepListController extends Controller
{
    /**
     * One step's work list by tab (pending, in_process, completed, rejected),
     * in the tab's fixed order. Readable by accounts too. export=csv downloads
     * the whole tab with the same filters.
     */
    public function __invoke(ListStepPassportsRequest $request, string $step, StepList $list, StepListCsv $csv): AnonymousResourceCollection|StreamedResponse
    {
        Gate::authorize('viewList', PassportStepRecord::class);

        $tab = $request->validated('tab') ?? 'pending';

        if ($request->validated('export') === 'csv') {
            return $csv->download($list->query($step, $tab, $request->validated()), $step, $tab, $request);
        }

        $page = $list->query($step, $tab, $request->validated())
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        $rows = StepListResource::collection($page);
        $rows->collection->each->forStep($step);

        // Each tab's total with the same search, for the tab badges (the same
        // tab definition as the rows and /workflow/summary).
        return $rows->additional(['tab_counts' => $list->tabCounts($step, $request->validated())]);
    }
}
