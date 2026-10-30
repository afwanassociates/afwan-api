<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListReferencesRequest;
use App\Http\Requests\DataEntry\StoreReferenceRequest;
use App\Http\Requests\DataEntry\UpdateReferenceRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\Reference;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Full reference management for the operations head, like companies
 * (Admin\CompanyController). The passport team keeps the search dropdown and
 * Quick Add (DataEntry\ReferenceController).
 */
class ReferenceController extends Controller
{
    /**
     * Every reference, active and inactive, with how many passports each has.
     */
    public function index(ListReferencesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Reference::class);

        $search = trim((string) $request->validated('q'));

        $references = Reference::query()
            ->withCount('passportEntries')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->when($request->validated('type'), fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ReferenceResource::collection($references);
    }

    /**
     * Create a reference (type, name, phone and notes). New references are active.
     */
    public function store(StoreReferenceRequest $request): ReferenceResource
    {
        Gate::authorize('manage', Reference::class);

        $reference = new Reference($request->validated());
        $reference->is_active = true;
        $reference->created_by = $request->user()->id;
        $reference->save();

        return new ReferenceResource($reference->loadCount('passportEntries'));
    }

    /**
     * Edit or (de)activate a reference.
     */
    public function update(UpdateReferenceRequest $request, Reference $reference): ReferenceResource
    {
        Gate::authorize('update', $reference);

        $reference->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $reference->is_active = $request->boolean('is_active');
        }

        $reference->save();

        return new ReferenceResource($reference->loadCount('passportEntries'));
    }

    /**
     * Soft delete a reference that has never had passports. A reference with
     * passports (deleted ones included) is deactivated instead.
     */
    public function destroy(Reference $reference): Response|JsonResponse
    {
        Gate::authorize('delete', $reference);

        if ($reference->passportEntries()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'This reference has passports, so it cannot be deleted. Deactivate it instead.',
                'code' => 'reference_has_passports',
            ], 422);
        }

        $reference->delete();

        return response()->noContent();
    }
}
