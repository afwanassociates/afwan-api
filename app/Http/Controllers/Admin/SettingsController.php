<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\AppSetting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    /**
     * The admin-managed settings.
     */
    public function show(Settings $settings): JsonResponse
    {
        Gate::authorize('update', AppSetting::class);

        return $this->respond($settings);
    }

    /**
     * Replace the settings, recording who changed them.
     */
    public function update(UpdateSettingsRequest $request, Settings $settings): JsonResponse
    {
        Gate::authorize('update', AppSetting::class);

        foreach ($request->validated() as $key => $value) {
            $settings->set($key, $value, $request->user());
        }

        return $this->respond($settings);
    }

    protected function respond(Settings $settings): JsonResponse
    {
        return response()->json(['data' => [
            // The stored values, as the admin form edits them.
            'default_company_country_code' => $settings->get(Settings::DEFAULT_COMPANY_COUNTRY),
            'default_passport_country_code' => $settings->get(Settings::DEFAULT_PASSPORT_COUNTRY),
            // What the passport form actually uses (with the MY fallback).
            ...$settings->defaultCountries(),
        ]]);
    }
}
