<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReferenceOptionsRequest;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReferenceOptionsController extends Controller
{
    /**
     * Up to 20 references (client parties) for the company report's "Filter
     * by reference" dropdown (accounts cannot use the data-entry lookup).
     * Inactive ones are listed too, since old passports still point to them.
     * With company_id, only references that have passports of that company.
     */
    public function __invoke(ReferenceOptionsRequest $request): JsonResponse
    {
        Gate::authorize('viewReport', Party::class);

        $search = trim((string) $request->validated('q'));

        $references = Party::query()
            ->clients()
            ->when($search !== '', fn (Builder $query) => $query->referenceSearch($search))
            ->when($request->validated('company_id'), fn (Builder $query, int|string $companyId) => $query
                ->whereHas('passportEntries', fn (Builder $query) => $query->where('company_id', $companyId)))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $references->map(fn (Party $reference) => [
                'id' => $reference->id,
                'name' => $reference->name,
                'type' => $reference->kind?->value,
                'type_label' => $reference->kind?->label(),
            ])->all(),
        ]);
    }
}
