<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * `$count` not-started passports of a company through a reference.
 */
function referencePassportsOf(Company $company, Reference $reference, int $count): void
{
    PassportEntry::factory()->count($count)->create([
        'company_id' => $company->id,
        'reference_id' => $reference->id,
        'passport_received_date' => '2026-01-15',
        'passport_expiry_date' => '2035-01-01',
        'medical_slip_date' => null,
    ]);
}

describe('company report company and reference filters', function () {
    it('filters by reference: only its passports, only companies that have some', function () {
        $alpha = Company::factory()->create(['name' => 'Alpha Builders', 'country_code' => 'MY', 'quota' => 10]);
        $beta = Company::factory()->create(['name' => 'Beta Foods', 'country_code' => 'MY']);
        $star = Reference::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        $karim = Reference::factory()->person()->create(['name' => 'Abdul Karim']);
        referencePassportsOf($alpha, $star, 2);
        referencePassportsOf($alpha, $karim, 3);
        referencePassportsOf($beta, $karim, 1);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $response = $this->getJson("/api/reports/companies?country_code=MY&reference_id={$star->id}")->assertOk();

        expect($response->json('data.*.id'))->toBe([$alpha->id])
            ->and($response->json('data.0.total'))->toBe(2)
            ->and($response->json('data.0.not_started'))->toBe(2)
            // The balance still counts every passport of the company (10 - 5).
            ->and($response->json('data.0.balance'))->toBe(5)
            ->and($response->json('totals.total'))->toBe(2)
            ->and($response->json('filters.reference.name'))->toBe('Sea Star Overseas')
            ->and($response->json('filters.reference.type_label'))->toBe('Agency')
            ->and($response->json('filters.company'))->toBeNull();

        expect($this->getJson("/api/reports/companies?country_code=MY&reference_id={$karim->id}")->json('data.*.total'))->toBe([3, 1]);
    });

    it('filters by company, also together with a reference', function () {
        $alpha = Company::factory()->create(['name' => 'Alpha Builders', 'country_code' => 'MY']);
        $beta = Company::factory()->create(['name' => 'Beta Foods', 'country_code' => 'MY']);
        $karim = Reference::factory()->person()->create(['name' => 'Abdul Karim']);
        referencePassportsOf($alpha, $karim, 3);
        referencePassportsOf($beta, $karim, 1);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson("/api/reports/companies?country_code=MY&company_id={$beta->id}")->assertOk();
        expect($response->json('data.*.id'))->toBe([$beta->id])
            ->and($response->json('filters.company'))->toBe(['id' => $beta->id, 'name' => 'Beta Foods', 'country_code' => 'MY']);

        expect($this->getJson("/api/reports/companies?country_code=MY&company_id={$alpha->id}&reference_id={$karim->id}")->json('data.*.total'))->toBe([3]);
    });

    it('rejects unknown ids', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/reports/companies?company_id=999&reference_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_id', 'reference_id']);
    });

    it('exports only the filtered rows as CSV', function () {
        $alpha = Company::factory()->create(['name' => 'Alpha Builders', 'country_code' => 'MY']);
        Company::factory()->create(['name' => 'Beta Foods', 'country_code' => 'MY']);
        $star = Reference::factory()->agency()->create(['name' => 'Sea Star']);
        referencePassportsOf($alpha, $star, 1);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $csv = $this->get("/api/reports/companies?country_code=MY&reference_id={$star->id}&export=csv")->assertOk()->streamedContent();

        expect($csv)->toContain('Alpha Builders')->not->toContain('Beta Foods');
    });
});

describe('reference dropdowns limited to a company', function () {
    it('lists only references with passports of the company, in the lookup and the reference report', function () {
        $alpha = Company::factory()->create(['country_code' => 'MY']);
        $beta = Company::factory()->create(['country_code' => 'MY']);
        $star = Reference::factory()->agency()->create(['name' => 'Sea Star']);
        $karim = Reference::factory()->person()->create(['name' => 'Abdul Karim']);
        Reference::factory()->person()->create(['name' => 'No Passports']);
        referencePassportsOf($alpha, $star, 1);
        referencePassportsOf($beta, $karim, 1);
        // A deleted passport does not count.
        PassportEntry::factory()->create(['company_id' => $alpha->id, 'reference_id' => $karim->id])->delete();

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        expect($this->getJson("/api/data-entry/references?company_id={$alpha->id}")->assertOk()->json('data.*.name'))->toBe(['Sea Star'])
            ->and($this->getJson("/api/data-entry/references?company_id={$beta->id}&q=kar")->json('data.*.name'))->toBe(['Abdul Karim'])
            ->and($this->getJson('/api/data-entry/references')->json('data'))->toHaveCount(3);

        // Accounts use the reports' reference options instead of the lookup.
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/references')->assertForbidden();
        expect($this->getJson("/api/reports/references?company_id={$alpha->id}")->assertOk()->json('data'))
            ->toBe([['id' => $star->id, 'name' => 'Sea Star', 'type' => 'agency', 'type_label' => 'Agency']])
            ->and($this->getJson('/api/reports/references?q=kar')->json('data.*.name'))->toBe(['Abdul Karim'])
            ->and($this->getJson('/api/reports/references')->json('data'))->toHaveCount(3);
        $this->getJson('/api/reports/references?company_id=999')->assertUnprocessable()->assertJsonValidationErrors('company_id');

        // The old reference report pages are gone.
        $this->getJson("/api/reports/references/{$star->id}/passports")->assertNotFound();
    });
});
