<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\DateRules;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DefaultsController extends Controller
{
    /**
     * Defaults the data-entry forms start with.
     */
    public function __invoke(Settings $settings, DateRules $rules): JsonResponse
    {
        Gate::authorize('view', AppSetting::class);

        $countries = $settings->defaultCountries();

        return response()->json(['data' => [
            ...$countries,
            // The bare codes, as before.
            'default_company_country_code' => $countries['default_company_country']['code'] ?? null,
            'default_passport_country_code' => $countries['default_passport_country']['code'] ?? null,
            // For prefilling a fit result's expiry date (issue date + days).
            'medical_validity_days' => $rules->get(DateRules::MEDICAL_VALIDITY_DAYS),
            'medical_expiring_soon_days' => $rules->get(DateRules::MEDICAL_EXPIRING_SOON_DAYS),
            // Every date rule (Settings → Date rules), for prefills, warnings and checks.
            'date_rules' => $rules->values(),
            // Each company country with its own values: every rule's value for it.
            'date_rules_by_country' => (object) $rules->valuesByCountry(),
        ]]);
    }
}
