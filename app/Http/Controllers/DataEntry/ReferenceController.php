<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Http\Requests\DataEntry\StoreReferenceRequest;
use App\Http\Requests\DataEntry\UpdateReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Reference;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReferenceController extends Controller
{
    /**
     * Up to 20 active references for the search dropdown; with company_id,
     * only references that have passports of that company. Admins may pass
     * all=1 for the full paginated list, inactive references included.
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

    /**
     * Edit or (de)activate a reference (admins only).
     */
    public function update(UpdateReferenceRequest $request, Reference $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        $reference->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $reference->is_active = $request->boolean('is_active');
        }

        $reference->save();

        return new ReferenceResource($reference);
    }

    /**
     * Soft delete a reference (admins only). Existing entries keep showing it;
     * the database refuses a hard delete while entries point to it.
     */
    public function destroy(Reference $reference): Response
    {
        Gate::authorize('delete', $reference);

        $reference->delete();

        return response()->noContent();
    }
}
