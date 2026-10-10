<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Http\Requests\DataEntry\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    /**
     * Up to 20 active companies for the search dropdown. Admins may pass
     * all=1 for the full paginated list, inactive companies included.
     */
    public function index(ListLookupRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Company::class);

        $all = $request->boolean('all');

        if ($all) {
            Gate::authorize('manage', Company::class);
        }

        $search = trim((string) $request->validated('q'));

        $companies = Company::query()
            ->with('country')
            ->unless($all, fn (Builder $query) => $query->where('is_active', true))
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
            ->orderBy('name');

        return CompanyResource::collection($all
            ? $companies->paginate($request->integer('per_page', 15))->withQueryString()
            : $companies->limit(20)->get());
    }

    /**
     * Quick Add a company from the passport form (any data-entry user): only
     * its name and country. Admins manage the other fields under /admin/companies.
     */
    public function store(StoreCompanyRequest $request): CompanyResource
    {
        Gate::authorize('create', Company::class);

        $company = new Company($request->safe()->only(['name', 'country_code']));
        $company->is_active = true;
        $company->created_by = $request->user()->id;
        $company->save();

        return new CompanyResource($company->load('country'));
    }
}
