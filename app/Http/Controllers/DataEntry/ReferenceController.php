<?php

namespace App\Http\Controllers\DataEntry;

use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Http\Requests\DataEntry\StoreReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * References for the passport team: client parties (agencies and persons).
 */
class ReferenceController extends Controller
{
    /**
     * Up to 20 active client parties for the search dropdown (by name, phone,
     * party ID or RL number); with company_id, only those that have passports
     * of that company. The operations head may pass all=1 for the full
     * paginated list (or use GET /api/admin/references).
     */
    public function index(ListLookupRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('searchReferences', Party::class);

        $all = $request->boolean('all');

        if ($all) {
            Gate::authorize('manageReferences', Party::class);
        }

        $search = trim((string) $request->validated('q'));

        $references = Party::query()
            ->clients()
            ->unless($all, fn (Builder $query) => $query->where('is_active', true))
            ->when($request->has('type'), fn (Builder $query) => $query->where('kind', $request->validated('type')))
            ->when($request->validated('company_id'), fn (Builder $query, int|string $companyId) => $query
                ->whereHas('passportEntries', fn (Builder $query) => $query->where('company_id', $companyId)))
            ->when($search !== '', fn (Builder $query) => $query->referenceSearch($search))
            ->orderBy('name');

        return ReferenceResource::collection($all
            ? $references->paginate($request->integer('per_page', 15))->withQueryString()
            : $references->limit(20)->get());
    }

    /**
     * Quick Add (any passport team user): a new client party, active, with the
     * next party ID.
     */
    public function store(StoreReferenceRequest $request): ReferenceResource
    {
        Gate::authorize('searchReferences', Party::class);

        $reference = new Party($request->partyAttributes());
        $reference->type = PartyType::Client;
        $reference->is_active = true;
        $reference->created_by = $request->user()->id;
        DB::transaction(fn () => $reference->save());

        return new ReferenceResource($reference);
    }
}
