<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListPassportEntriesRequest;
use App\Http\Requests\DataEntry\StorePassportEntryRequest;
use App\Http\Requests\DataEntry\UpdatePassportEntryRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Support\Like;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PassportEntryController extends Controller
{
    /**
     * Paginated, searchable list of passport entries.
     */
    public function index(ListPassportEntriesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PassportEntry::class);

        $search = trim((string) $request->validated('q'));
        $sort = $request->validated('sort', 'created_at');
        $direction = $request->validated('direction', 'desc');

        $entries = PassportEntry::query()
            ->with(PassportEntryResource::RELATIONS)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('passport_name', 'like', Like::contains($search))
                ->orWhere('passport_number', 'like', Like::contains((string) Normalize::passportNumber($search)))
                ->orWhereHas('reference', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                ->orWhereHas('company', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))))
            ->when($request->has('reference_id'), fn (Builder $query) => $query->where('reference_id', $request->integer('reference_id')))
            ->when($request->has('reference_type'), fn (Builder $query) => $query->whereHas('reference', fn (Builder $query) => $query->where('type', $request->validated('reference_type'))))
            ->when($request->has('company_id'), fn (Builder $query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->has('company_country_code'), fn (Builder $query) => $query->whereHas('company', fn (Builder $query) => $query->where('country_code', $request->validated('company_country_code'))))
            ->when($request->has('received_from'), fn (Builder $query) => $query->whereDate('passport_received_date', '>=', $request->validated('received_from')))
            ->when($request->has('received_to'), fn (Builder $query) => $query->whereDate('passport_received_date', '<=', $request->validated('received_to')))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return PassportEntryResource::collection($entries);
    }

    /**
     * Record a received passport.
     */
    public function store(StorePassportEntryRequest $request): PassportEntryResource
    {
        Gate::authorize('create', PassportEntry::class);

        $entry = new PassportEntry($request->validated());
        $entry->created_by = $request->user()->id;
        $entry->updated_by = $request->user()->id;
        $entry->save();

        return new PassportEntryResource($entry->load(PassportEntryResource::RELATIONS));
    }

    /**
     * A single passport entry.
     */
    public function show(PassportEntry $passport): PassportEntryResource
    {
        Gate::authorize('view', $passport);

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }

    /**
     * Edit a passport entry.
     */
    public function update(UpdatePassportEntryRequest $request, PassportEntry $passport): PassportEntryResource
    {
        Gate::authorize('update', $passport);

        $passport->fill($request->validated());
        $passport->updated_by = $request->user()->id;
        $passport->save();

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }

    /**
     * Soft delete a passport entry, recording who deleted it. Its passport
     * number stays taken.
     */
    public function destroy(Request $request, PassportEntry $passport): Response
    {
        Gate::authorize('delete', $passport);

        $passport->deleted_by = $request->user()->id;
        $passport->save();
        $passport->delete();

        return response()->noContent();
    }
}
