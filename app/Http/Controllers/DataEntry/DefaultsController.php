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

        return response()->json(['data' => [
            'default_company_country_code' => $settings->get(Settings::DEFAULT_COMPANY_COUNTRY),
            'default_passport_country_code' => $settings->get(Settings::DEFAULT_PASSPORT_COUNTRY),
        ]]);
    }
}
