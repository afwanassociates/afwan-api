<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListLookupRequest;
use App\Http\Requests\DataEntry\StoreCompanyRequest;
use App\Http\Requests\DataEntry\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
     * Create a company (any data-entry user).
     */
    public function store(StoreCompanyRequest $request): CompanyResource
    {
        Gate::authorize('create', Company::class);

        $company = new Company($request->validated());
        $company->is_active = true;
        $company->created_by = $request->user()->id;
        $company->save();

        return new CompanyResource($company->load('country'));
    }

    /**
     * Edit or (de)activate a company (admins only).
     */
    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        Gate::authorize('update', $company);

        $company->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $company->is_active = $request->boolean('is_active');
        }

        $company->save();

        return new CompanyResource($company->load('country'));
    }

    /**
     * Soft delete a company (admins only). Existing entries keep showing it;
     * the database refuses a hard delete while entries point to it.
     */
    public function destroy(Company $company): Response
    {
        Gate::authorize('delete', $company);

        $company->delete();

        return response()->noContent();
    }
}
