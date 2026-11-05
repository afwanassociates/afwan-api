<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListReferencesRequest;
use App\Http\Requests\DataEntry\StoreReferenceRequest;
use App\Http\Requests\DataEntry\UpdateReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reference management for the operations head: client parties (agencies and
 * persons), the same records the accounts team keeps on the Party / Owner
 * page. Nobody deletes a party: deactivate it instead. The passport team keeps
 * the search dropdown and Quick Add (DataEntry\ReferenceController).
 */
class ReferenceController extends Controller
{
    /**
     * Every client party, active and inactive, with how many passports each has.
     */
    public function index(ListReferencesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manageReferences', Party::class);

        $search = trim((string) $request->validated('q'));

        $references = Party::query()
            ->clients()
            ->withCount('passportEntries')
            ->when($search !== '', fn (Builder $query) => $query->referenceSearch($search))
            ->when($request->validated('type'), fn (Builder $query, string $type) => $query->where('kind', $type))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ReferenceResource::collection($references);
    }

    /**
     * Create a client party (type, name, phone, RL number for an agency, notes).
     */
    public function store(StoreReferenceRequest $request): ReferenceResource
    {
        Gate::authorize('manageReferences', Party::class);

        $reference = new Party($request->partyAttributes());
        $reference->type = PartyType::Client;
        $reference->is_active = true;
        $reference->created_by = $request->user()->id;
        DB::transaction(fn () => $reference->save());

        return new ReferenceResource($reference->loadCount('passportEntries'));
    }

    /**
     * Edit or (de)activate a client party.
     */
    public function update(UpdateReferenceRequest $request, Party $reference): ReferenceResource
    {
        Gate::authorize('updateReference', $reference);

        $reference->fill($request->partyAttributes());

        if ($request->has('is_active')) {
            $reference->is_active = $request->boolean('is_active');
        }

        $reference->save();

        return new ReferenceResource($reference->loadCount('passportEntries'));
    }
}
