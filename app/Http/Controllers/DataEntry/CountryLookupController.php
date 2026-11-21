<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CountryLookupController extends Controller
{
    /**
     * How long the browser may reuse the list. Admin changes show up after this.
     */
    public const MAX_AGE = 300;

    /**
     * Active countries for dropdowns: pinned first, then the rest by name.
     */
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Country::class);

        $countries = Country::query()
            ->active()
            ->dropdownOrder()
            ->get(['code', 'name', 'is_pinned'])
            ->map(fn (Country $country) => [
                'code' => $country->code,
                'name' => $country->name,
                'is_pinned' => $country->is_pinned,
            ]);

        return response()
            ->json(['data' => $countries])
            ->header('Cache-Control', 'private, max-age='.self::MAX_AGE);
    }
}
