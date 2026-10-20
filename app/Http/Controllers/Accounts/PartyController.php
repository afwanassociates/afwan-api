<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListPartiesRequest;
use App\Http\Requests\Accounts\StorePartyRequest;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Parties (clients) and owners, with their ledger totals.
 */
class PartyController extends Controller
{
    /**
     * Parties of a type (or all), newest party ID first, with totals.
     */
    public function index(ListPartiesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Party::class);

        $search = trim((string) $request->validated('q'));

        $parties = Party::query()
            ->withTotals()
            ->when($request->validated('type'), fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $pattern = Like::contains($search);
                $query->where('name', 'like', $pattern)
                    ->orWhere('code', 'like', $pattern)
                    ->orWhere('mobile', 'like', $pattern)
                    ->orWhere('nid_passport', 'like', $pattern);
            }))
            ->orderBy('type')
            ->orderByDesc('sequence')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return PartyResource::collection($parties);
    }

    public function show(Party $party): PartyResource
    {
        Gate::authorize('view', $party);

        return $this->resource($party);
    }

    /**
     * Create a party or owner; it gets the next ID of its type.
     */
    public function store(StorePartyRequest $request): PartyResource
    {
        Gate::authorize('create', Party::class);

        $party = DB::transaction(function () use ($request) {
            $party = new Party($request->safe()->except('type'));
            $party->type = PartyType::from($request->validated('type'));
            $party->is_active = true;
            $party->created_by = $request->user()->id;
            $party->save();

            return $party;
        });

        return $this->resource($party);
    }

    /**
     * Edit or (de)activate a party. The type and party ID never change.
     */
    public function update(StorePartyRequest $request, Party $party): PartyResource
    {
        Gate::authorize('update', $party);

        $party->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $party->is_active = $request->boolean('is_active');
        }

        $party->save();

        return $this->resource($party);
    }

    /**
     * Delete a party with no ledger lines. One with transactions is
     * deactivated instead, so its history stays.
     */
    public function destroy(Party $party): Response|JsonResponse
    {
        Gate::authorize('delete', $party);

        if ($party->transactions()->exists() || $party->cashReceipts()->exists() || $party->cashPayments()->exists()) {
            return response()->json([
                'message' => 'This party has transactions, so it cannot be deleted. Deactivate it instead.',
                'code' => 'party_has_transactions',
            ], 422);
        }

        $party->delete();

        return response()->noContent();
    }

    private function resource(Party $party): PartyResource
    {
        $fresh = Party::query()->withTotals()->findOrFail($party->id);
        // Keep the 201 status of a newly created party.
        $fresh->wasRecentlyCreated = $party->wasRecentlyCreated;

        return new PartyResource($fresh);
    }
}
