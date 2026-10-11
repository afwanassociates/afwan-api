<?php

use App\Enums\ReferenceType;
use App\Models\Company;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 12:00', 'Asia/Dhaka')->utc());
    Sanctum::actingAs(User::factory()->dataEntry()->create());
});

/**
 * IDs in one Medical page tab for the given extra query string.
 *
 * @return list<int>
 */
function medicalTabIds(string $tab, string $query = ''): array
{
    return test()->getJson("/api/data-entry/passports?mode=medical&medical_status={$tab}&per_page=100&{$query}")->assertOk()->json('data.*.id');
}

/**
 * A passport for the company and reference, in the given medical state
 * (pending, fit, expiring_soon, expired or unfit).
 *
 * @param  array<string, mixed>  $attributes
 */
function medicalTabPassport(string $state, array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip($attributes['medical_slip_date'] ?? '2026-04-01')->create($attributes);
    $factory = MedicalRecord::factory()->for($passport);

    match ($state) {
        'pending' => null,
        'fit' => $factory->validUntil('2026-07-01')->create(),
        'expiring_soon' => $factory->validUntil('2026-05-10')->create(),
        'expired' => $factory->validUntil('2026-04-20')->create(),
        'unfit' => $factory->unfit()->on('2026-04-20')->create(),
    };

    return $passport->fresh();
}

describe('tabs and search', function () {
    it('matches company and reference partially, in any case, combined, on every tab', function (string $tab) {
        $rose = Company::factory()->create(['name' => 'Desert Rose Construction']);
        $gulf = Company::factory()->create(['name' => 'Gulf Star Catering']);
        $karim = Reference::factory()->create(['type' => ReferenceType::Person, 'name' => 'Abdul Karim']);
        $agency = Reference::factory()->create(['type' => ReferenceType::Agency, 'name' => 'Sea Star Overseas']);

        $roseKarim = medicalTabPassport($tab, ['company_id' => $rose->id, 'reference_id' => $karim->id, 'passport_number' => 'RK1111111']);
        $roseAgency = medicalTabPassport($tab, ['company_id' => $rose->id, 'reference_id' => $agency->id, 'passport_number' => 'RA2222222']);
        $gulfKarim = medicalTabPassport($tab, ['company_id' => $gulf->id, 'reference_id' => $karim->id, 'passport_number' => 'GK3333333']);
        // Another tab, matching everything else: never in this one.
        medicalTabPassport($tab === 'pending' ? 'fit' : 'pending', ['company_id' => $rose->id, 'reference_id' => $karim->id]);

        expect(medicalTabIds($tab, 'company=ROSE'))->toEqualCanonicalizing([$roseKarim->id, $roseAgency->id])
            ->and(medicalTabIds($tab, 'reference=karim'))->toEqualCanonicalizing([$roseKarim->id, $gulfKarim->id])
            ->and(medicalTabIds($tab, 'company=desert&reference=KARIM'))->toBe([$roseKarim->id])
            ->and(medicalTabIds($tab, 'company=star cat&reference=abdul'))->toBe([$gulfKarim->id])
            ->and(medicalTabIds($tab, 'company=rose&q=RA2222222'))->toBe([$roseAgency->id])
            ->and(medicalTabIds($tab, 'company=nowhere'))->toBe([]);
    })->with(['pending', 'fit', 'expiring_soon', 'expired', 'unfit']);

    it('shows only pending (slip entered, no result) on the Pending tab, and defaults to it', function () {
        $pending = medicalTabPassport('pending');
        PassportEntry::factory()->create(); // not started: Passport list only
        medicalTabPassport('fit');

        expect(medicalTabIds('pending'))->toBe([$pending->id])
            ->and($this->getJson('/api/data-entry/passports?mode=medical')->json('data.*.id'))->toBe([$pending->id]);
    });

    it('accepts only the five tabs', function (string $tab) {
        $this->getJson("/api/data-entry/passports?mode=medical&medical_status={$tab}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_status');
    })->with(['not_started', 'all', 'bogus']);
});

describe('payload', function () {
    it('includes the reference and medical centre, and no waiting days', function () {
        $center = MedicalCenter::query()->where('name', 'Meem Medical Center')->sole();
        $reference = Reference::factory()->create(['name' => 'Abdul Karim']);
        medicalTabPassport('pending', ['reference_id' => $reference->id, 'medical_center_id' => $center->id]);

        $row = $this->getJson('/api/data-entry/passports?mode=medical&medical_status=pending')->assertOk()->json('data.0');

        expect($row['reference'])->toMatchArray(['id' => $reference->id, 'name' => 'Abdul Karim'])
            ->and($row['medical_center'])->toBe(['id' => $center->id, 'name' => 'Meem Medical Center'])
            ->and($row)->not->toHaveKey('waiting_days')
            ->and(json_encode($row))->not->toContain('waiting_days');
    });

    it('returns a null medical centre when none is set', function () {
        medicalTabPassport('fit');

        expect($this->getJson('/api/data-entry/passports?mode=medical&medical_status=fit')->json('data.0.medical_center'))->toBeNull();
    });
});

describe('ordering', function () {
    it('orders Pending by slip date oldest first, then id, ignoring sort', function () {
        $late = medicalTabPassport('pending', ['medical_slip_date' => '2026-04-20']);
        $early = medicalTabPassport('pending', ['medical_slip_date' => '2026-03-01']);
        $sameDayFirst = medicalTabPassport('pending', ['medical_slip_date' => '2026-04-10']);
        $sameDaySecond = medicalTabPassport('pending', ['medical_slip_date' => '2026-04-10']);

        $expected = [$early->id, $sameDayFirst->id, $sameDaySecond->id, $late->id];

        expect(medicalTabIds('pending'))->toBe($expected)
            ->and(medicalTabIds('pending', 'sort=created_at&direction=desc'))->toBe($expected)
            ->and(medicalTabIds('pending', 'sort=bogus'))->toBe($expected);
    });

    it('orders the other tabs newest first, ignoring sort', function (string $tab) {
        $first = medicalTabPassport($tab);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $second = medicalTabPassport($tab);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $third = medicalTabPassport($tab);

        $expected = [$third->id, $second->id, $first->id];

        expect(medicalTabIds($tab))->toBe($expected)
            ->and(medicalTabIds($tab, 'sort=medical_date&direction=asc'))->toBe($expected);
    })->with(['fit', 'expiring_soon', 'expired', 'unfit']);
});

describe('other callers', function () {
    it('keep sort, company_id and country working without the mode', function () {
        $first = medicalTabPassport('pending', ['medical_slip_date' => '2026-04-20']);
        $second = medicalTabPassport('pending', ['medical_slip_date' => '2026-03-01']);
        $ids = fn (string $query) => $this->getJson("/api/data-entry/passports?per_page=100&{$query}")->assertOk()->json('data.*.id');

        expect($ids('medical_status=pending&sort=created_at&direction=asc'))->toBe([$first->id, $second->id])
            ->and($ids('medical_status=pending&sort=created_at&direction=desc'))->toBe([$second->id, $first->id])
            ->and($ids("company_id={$first->company_id}"))->toBe([$first->id])
            ->and($ids("company_country_code={$first->company->country_code}"))->toContain($first->id)
            ->and($ids('medical_status=not_started'))->toBe([]);

        $this->getJson('/api/data-entry/passports?sort=bogus')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });
});
