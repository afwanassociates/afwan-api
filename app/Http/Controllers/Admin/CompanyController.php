<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCompaniesRequest;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\DataEntry\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Full company management for admin and super_admin. data_entry keeps only
 * the search dropdown and Quick Add (DataEntry\CompanyController).
 */
class CompanyController extends Controller
{
    /**
     * Every company, active and inactive, with how many passports each has.
     */
    public function index(ListCompaniesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Company::class);

        $search = trim((string) $request->validated('q'));

        $companies = Company::query()
            ->with('country')
            ->withCount('passportEntries')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', Like::contains($search))
                ->orWhere('agent_name', 'like', Like::contains($search))
                ->orWhere('bd_agency_name', 'like', Like::contains($search))))
            ->when($request->validated('country_code'), fn (Builder $query, string $code) => $query->where('country_code', $code))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return CompanyResource::collection($companies);
    }

    /**
     * One company.
     */
    public function show(Company $company): CompanyResource
    {
        Gate::authorize('manage', Company::class);

        return new CompanyResource($company->load('country')->loadCount('passportEntries'));
    }

    /**
     * Create a company with every field. Active unless is_active=false.
     */
    public function store(StoreCompanyRequest $request): CompanyResource
    {
        Gate::authorize('manage', Company::class);

        $company = new Company($request->safe()->except('is_active'));
        $company->is_active = $request->boolean('is_active', true);
        $company->created_by = $request->user()->id;
        $company->save();

        return new CompanyResource($company->load('country')->loadCount('passportEntries'));
    }

    /**
     * Edit or (de)activate a company.
     */
    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        Gate::authorize('update', $company);

        $company->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $company->is_active = $request->boolean('is_active');
        }

        $company->save();

        return new CompanyResource($company->load('country')->loadCount('passportEntries'));
    }

    /**
     * Soft delete a company that has never had passports. A company with
     * passports (deleted ones included) is deactivated instead.
     */
    public function destroy(Company $company): Response|JsonResponse
    {
        Gate::authorize('delete', $company);

        if ($company->passportEntries()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'This company has passports, so it cannot be deleted. Deactivate it instead.',
                'code' => 'company_has_passports',
            ], 422);
        }

        $company->delete();

        return response()->noContent();
    }
}
