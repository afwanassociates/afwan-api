<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Country;
use App\Support\DateRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Settings → Date rules, for the super admin only (the route group "role:super_admin" and
 * the check below): validity periods, expiry warnings and the passport limits of each
 * company country (where the worker goes). A country the company starts working in gets
 * its rules here, every rule filled in.
 */
class DateRulesController extends Controller
{
    public function __construct(private readonly DateRules $rules) {}

    /**
     * GET /super-admin/date-rules[?country=MY] — every rule (unit, limits) with the
     * country's value (null when it has none, e.g. a new country). meta lists the
     * countries with rules and the company countries still without them.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return $this->respond($this->country($request, required: false));
    }

    /**
     * PUT /super-admin/date-rules — save a country's rules: "country" and whole numbers
     * within each rule's limits. A country without rules yet must send every rule; one with
     * rules may send only those that change.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $country = $this->country($request, required: true);
        $isNew = $this->rules->countryValuesCount($country) === 0;

        $rules = [];
        $messages = [];
        foreach ($this->rules->definitions() as $key => $rule) {
            $rules[$key] = [$isNew ? 'required' : 'sometimes', 'integer', "min:{$rule['min']}", "max:{$rule['max']}"];
            $messages["{$key}.required"] = "Enter {$rule['label']} ({$rule['unit']}).";
            $messages["{$key}.integer"] = "{$rule['label']} must be a whole number of {$rule['unit']}.";
            $messages["{$key}.min"] = "{$rule['label']} must be at least {$rule['min']} {$rule['unit']}.";
            $messages["{$key}.max"] = "{$rule['label']} may not be more than {$rule['max']} {$rule['unit']}.";
        }

        $validated = $request->validate($rules, $messages);
        $this->rules->save($validated, $request->user(), $country);

        return $this->respond($country);
    }

    /**
     * DELETE /super-admin/date-rules/countries/{code} — remove a country's rules (its
     * passports use the built-in defaults until it gets rules again).
     */
    public function destroyCountry(Request $request, string $code): Response
    {
        $this->authorizeSuperAdmin($request);

        $this->rules->clearCountry(strtoupper($code), $request->user());

        return response()->noContent();
    }

    /** The ?country= / "country" (an existing country code). */
    private function country(Request $request, bool $required): ?string
    {
        $request->validate([
            'country' => [$required ? 'required' : 'sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
        ], [
            'country.required' => 'Choose the country these rules are for.',
            'country.exists' => 'That country does not exist.',
        ]);

        $code = $request->input('country');

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    private function respond(?string $country): JsonResponse
    {
        $definitions = $this->rules->definitions();
        $codes = $this->rules->countries();

        // Company countries without (complete) rules: their passports use the defaults.
        $companies = Company::query()->where('is_active', true)
            ->selectRaw('country_code, count(*) as total')->groupBy('country_code')->pluck('total', 'country_code');
        $missing = $companies->keys()->filter(fn (string $code) => ! $this->rules->isComplete($code))->values();

        $names = Country::query()
            ->whereIn('code', [...$codes, ...$missing, ...($country ? [$country] : [])])
            ->pluck('name', 'code');

        return response()->json([
            'data' => collect($definitions)->map(fn (array $rule, string $key) => [
                'key' => $key,
                ...collect($rule)->except('default')->all(),
                'value' => $country === null ? null : $this->rules->ownValue($key, $country),
            ])->values()->all(),
            'meta' => [
                'country' => $country === null ? null : ['code' => $country, 'name' => $names[$country] ?? $country],
                'countries' => array_map(fn (string $code) => [
                    'code' => $code,
                    'name' => $names[$code] ?? $code,
                    'complete' => $this->rules->isComplete($code),
                    'companies' => (int) ($companies[$code] ?? 0),
                ], $codes),
                // Company countries the rules are not set for yet.
                'missing' => $missing->map(fn (string $code) => [
                    'code' => $code,
                    'name' => $names[$code] ?? $code,
                    'companies' => (int) $companies[$code],
                ])->all(),
            ],
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::SuperAdmin), 403, 'Only a super admin can change the date rules.');
    }
}
