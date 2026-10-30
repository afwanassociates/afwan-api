<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCountriesRequest;
use App\Http\Requests\Admin\StoreCountryRequest;
use App\Http\Requests\Admin\UpdateCountryRequest;
use App\Http\Resources\CountryResource;
use App\Models\Country;
use App\Support\Like;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CountryController extends Controller
{
    /**
     * Every country, active and inactive, with how often each is used.
     */
    public function index(ListCountriesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('manage', Country::class);

        $search = trim((string) $request->validated('q'));

        $countries = Country::query()
            ->withCount(['companies', 'passportEntries'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', Like::contains($search))
                ->orWhere('code', Normalize::countryCode($search))))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->dropdownOrder()
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return CountryResource::collection($countries);
    }

    /**
     * Add a country. New countries are active.
     */
    public function store(StoreCountryRequest $request): CountryResource
    {
        Gate::authorize('create', Country::class);

        $country = new Country($request->validated());
        $country->is_active = true;
        $country->updated_by = $request->user()->id;
        $country->save();

        return new CountryResource($country->loadCount(['companies', 'passportEntries']));
    }

    /**
     * Rename, (de)activate, pin or reorder a country. Countries are never deleted.
     */
    public function update(UpdateCountryRequest $request, Country $country): CountryResource
    {
        Gate::authorize('update', $country);

        $country->fill($request->safe()->except('is_active'));

        if ($request->has('is_active')) {
            $country->is_active = $request->boolean('is_active');
        }

        $country->updated_by = $request->user()->id;
        $country->save();

        return new CountryResource($country->loadCount(['companies', 'passportEntries']));
    }
}
