<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Models\MedicalCenter;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MedicalCenterLookupController extends Controller
{
    /**
     * Up to 20 active medical centres matching the search, for the dropdown.
     */
    public function __invoke(ListLookupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', MedicalCenter::class);

        $search = trim((string) $request->validated('q'));

        $centers = MedicalCenter::query()
            ->active()
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name'])
            ->map(fn (MedicalCenter $center) => ['id' => $center->id, 'name' => $center->name]);

        return response()->json(['data' => $centers]);
    }
}
