<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DefaultsController extends Controller
{
    /**
     * Defaults the data-entry forms start with.
     */
    public function __invoke(Settings $settings): JsonResponse
    {
        Gate::authorize('view', AppSetting::class);

        $countries = $settings->defaultCountries();

        return response()->json(['data' => [
            ...$countries,
            // The bare codes, as before.
            'default_company_country_code' => $countries['default_company_country']['code'] ?? null,
            'default_passport_country_code' => $countries['default_passport_country']['code'] ?? null,
        ]]);
    }
}
