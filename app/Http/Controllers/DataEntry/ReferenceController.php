<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Http\Requests\DataEntry\StoreReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Reference;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReferenceController extends Controller
{
    /**
     * Up to 20 active references for the search dropdown; with company_id,
     * only references that have passports of that company. The operations head
     * may pass all=1 for the full paginated list (or use GET /api/admin/references).
     */
    public function index(ListLookupRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Reference::class);

        $all = $request->boolean('all');

        if ($all) {
            Gate::authorize('manage', Reference::class);
        }

        $search = trim((string) $request->validated('q'));

        $references = Reference::query()
            ->unless($all, fn (Builder $query) => $query->where('is_active', true))
            ->when($request->has('type'), fn (Builder $query) => $query->where('type', $request->validated('type')))
            ->when($request->validated('company_id'), fn (Builder $query, int|string $companyId) => $query
                ->whereHas('passportEntries', fn (Builder $query) => $query->where('company_id', $companyId)))
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->orderBy('name');

        return ReferenceResource::collection($all
            ? $references->paginate($request->integer('per_page', 15))->withQueryString()
            : $references->limit(20)->get());
    }

    /**
     * Create a reference (any data-entry user).
     */
    public function store(StoreReferenceRequest $request): ReferenceResource
    {
        Gate::authorize('create', Reference::class);

        $reference = new Reference($request->validated());
        $reference->is_active = true;
        $reference->created_by = $request->user()->id;
        $reference->save();

        return new ReferenceResource($reference);
    }
}
