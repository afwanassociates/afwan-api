<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PartyCompanyRate;
use App\Models\PassportEntry;
use App\Support\PassportPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Passport prices for the payment report: companies' standard rates,
 * references' own rates for a company and special prices on one passport.
 * The accounts head and the super admin read them; only the accounts head
 * changes them (routes/api.php).
 */
class PassportRatesController extends Controller
{
    /** Every company with its standard rate, and every reference rate. */
    public function index(): JsonResponse
    {
        $passports = DB::table('passport_entries')
            ->whereNull('deleted_at')
            ->whereNotNull('reference_id')
            ->selectRaw('company_id, count(*) as total, sum(case when price is null then 1 else 0 end) as no_price')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        return response()->json([
            'companies' => Company::query()
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'rate', 'is_active'])
                ->map(fn (Company $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'country_code' => $c->country_code,
                    'is_active' => $c->is_active,
                    'rate' => $c->rate !== null ? (float) $c->rate : null,
                    'passports' => (int) ($passports[$c->id]->total ?? 0),
                    'no_price' => (int) ($passports[$c->id]->no_price ?? 0),
                ])->all(),
            'references' => PartyCompanyRate::query()
                ->with(['party:id,code,name', 'company:id,name,country_code,rate'])
                ->get()
                ->sortBy(fn (PartyCompanyRate $r) => mb_strtolower($r->party->name.' '.$r->company->name))
                ->map(fn (PartyCompanyRate $r) => [
                    'reference' => ['id' => $r->party->id, 'code' => $r->party->code, 'name' => $r->party->name],
                    'company' => ['id' => $r->company->id, 'name' => $r->company->name, 'country_code' => $r->company->country_code],
                    'rate' => (float) $r->rate,
                    'company_rate' => $r->company->rate !== null ? (float) $r->company->rate : null,
                ])->values()->all(),
        ]);
    }

    public function company(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'update_existing' => ['boolean'],
        ]);

        return response()->json(DB::transaction(fn () => PassportPricing::setCompanyRate(
            $company,
            $data['rate'] !== null ? (float) $data['rate'] : null,
            (bool) ($data['update_existing'] ?? false),
        )));
    }

    public function reference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('type', PartyType::Client->value)],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'update_existing' => ['boolean'],
        ]);

        return response()->json(DB::transaction(fn () => PassportPricing::setReferenceRate(
            (int) $data['party_id'],
            (int) $data['company_id'],
            $data['rate'] !== null ? (float) $data['rate'] : null,
            (bool) ($data['update_existing'] ?? false),
        )));
    }

    /** A special price on one passport; null goes back to the rate. */
    public function passport(Request $request, PassportEntry $passport): JsonResponse
    {
        $data = $request->validate([
            'price' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        PassportPricing::setSpecial($passport, $data['price'] !== null ? (float) $data['price'] : null);

        return response()->json([
            'price' => $passport->price !== null ? (float) $passport->price : null,
            'price_source' => $passport->price_source,
        ]);
    }
}
