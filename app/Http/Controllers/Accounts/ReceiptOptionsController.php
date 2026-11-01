<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class ReceiptOptionsController extends Controller
{
    /**
     * What the cash receive form offers a party: the business types, active
     * countries (pinned first) and the default country (the default passport
     * country) that recruitment starts with.
     */
    public function __invoke(Settings $settings): JsonResponse
    {
        $countries = Country::query()
            ->active()
            ->dropdownOrder()
            ->get(['code', 'name', 'is_pinned'])
            ->map(fn (Country $country) => [
                'code' => $country->code,
                'name' => $country->name,
                'is_pinned' => $country->is_pinned,
            ]);

        return response()->json(['data' => [
            'business_types' => array_map(
                fn (BusinessType $type) => ['value' => $type->value, 'label' => $type->label()],
                BusinessType::cases(),
            ),
            'default_business_type' => BusinessType::Recruitment->value,
            'countries' => $countries,
            'default_country' => $settings->defaultCountries()['default_passport_country'],
        ]]);
    }
}
