<?php

use App\Enums\ReferenceType;
use App\Models\Company;
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
 * IDs in the Passport List mode for the given extra query string.
 *
 * @return list<int>
 */
function enteredIds(string $query = ''): array
{
    return test()->getJson("/api/data-entry/passports?mode=passport_entered&per_page=100&{$query}")->assertOk()->json('data.*.id');
}

/**
 * One passport of each kind: just entered (two), slip entered, fit, unfit,
 * and a deleted one that was just entered.
 *
 * @return array<string, PassportEntry>
 */
function enteredMix(): array
{
    $passports = [
        'entered' => PassportEntry::factory()->create(),
        'entered2' => PassportEntry::factory()->create(),
        'slipped' => PassportEntry::factory()->withMedicalSlip()->create(),
        'fit' => PassportEntry::factory()->withMedicalSlip()->create(),
        'unfit' => PassportEntry::factory()->withMedicalSlip()->create(),
        'deleted' => PassportEntry::factory()->create(),
    ];

    MedicalRecord::factory()->for($passports['fit'])->on('2026-04-20')->create();
    MedicalRecord::factory()->for($passports['unfit'])->unfit()->on('2026-04-20')->create();
    $passports['deleted']->delete();

    return $passports;
}

describe('passport entered mode', function () {
    it('returns only passports whose medical has not started, newest first', function () {
        $p = enteredMix();

        $response = $this->getJson('/api/data-entry/passports?mode=passport_entered')->assertOk();

        expect($response->json('data.*.id'))->toBe([$p['entered2']->id, $p['entered']->id])
            ->and($response->json('data.*.medical_status'))->each->toBe('not_started')
            ->and($response->json('data.*.current_stage'))->each->toBe('passport');
    });

    it('drops a passport once its medical slip is saved', function () {
        $passport = PassportEntry::factory()->create();

        expect(enteredIds())->toBe([$passport->id]);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-30'])->assertOk();

        expect(enteredIds())->toBe([]);

        // Clearing the slip (no result yet) brings it back.
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => null])->assertOk();

        expect(enteredIds())->toBe([$passport->id]);
    });

    it('never includes unfit passports, whatever medical_status says', function () {
        $p = enteredMix();

        expect(enteredIds('medical_status=unfit'))->toEqualCanonicalizing([$p['entered']->id, $p['entered2']->id])
            ->and(enteredIds('medical_status=all'))->toEqualCanonicalizing([$p['entered']->id, $p['entered2']->id])
            ->and(enteredIds())->not->toContain($p['unfit']->id);
    });

    it('works with the overview view too', function () {
        $p = enteredMix();

        $this->getJson('/api/data-entry/passports?mode=passport_entered&view=overview')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.latest_status.text', 'Passport: Not Started')
            ->assertJsonMissingPath('data.0.workflow');
    });

    it('rejects an unknown mode', function () {
        $this->getJson('/api/data-entry/passports?mode=bogus')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');
    });
});

describe('reference and company search in the mode', function () {
    it('matches partially, in any case, and combines with each other and the search', function () {
        $rose = Company::factory()->create(['name' => 'Desert Rose Construction']);
        $gulf = Company::factory()->create(['name' => 'Gulf Star Catering']);
        $karim = Reference::factory()->create(['type' => ReferenceType::Person, 'name' => 'Abdul Karim']);
        $agency = Reference::factory()->create(['type' => ReferenceType::Agency, 'name' => 'Sea Star Overseas']);

        $roseKarim = PassportEntry::factory()->create(['company_id' => $rose->id, 'reference_id' => $karim->id, 'passport_number' => 'RK1111111']);
        $roseAgency = PassportEntry::factory()->create(['company_id' => $rose->id, 'reference_id' => $agency->id, 'passport_number' => 'RA2222222']);
        $gulfKarim = PassportEntry::factory()->create(['company_id' => $gulf->id, 'reference_id' => $karim->id, 'passport_number' => 'GK3333333']);
        // Slip entered: never in this mode, even when everything else matches.
        PassportEntry::factory()->withMedicalSlip()->create(['company_id' => $rose->id, 'reference_id' => $karim->id]);

        expect(enteredIds('reference=KARIM'))->toEqualCanonicalizing([$roseKarim->id, $gulfKarim->id])
            ->and(enteredIds('company=desert rose'))->toEqualCanonicalizing([$roseKarim->id, $roseAgency->id])
            ->and(enteredIds('company=ROSE&reference=karim'))->toBe([$roseKarim->id])
            ->and(enteredIds('company=star&reference=karim'))->toBe([$gulfKarim->id])
            ->and(enteredIds('company=rose&q=RA2222222'))->toBe([$roseAgency->id])
            ->and(enteredIds('company=rose&reference=karim&q=GK3333333'))->toBe([])
            ->and(enteredIds('company=nowhere'))->toBe([]);
    });
});

describe('other modes and params', function () {
    it('leave the default list, medical_status, stage and the exact filters as they were', function () {
        $p = enteredMix();
        $ids = fn (string $query) => $this->getJson("/api/data-entry/passports?per_page=100&{$query}")->assertOk()->json('data.*.id');

        // Default: every non-unfit passport, slip and fit included.
        expect($ids(''))->toEqualCanonicalizing([$p['entered']->id, $p['entered2']->id, $p['slipped']->id, $p['fit']->id])
            ->and($ids('medical_status=all'))->toHaveCount(5)
            ->and($ids('medical_status=pending'))->toBe([$p['slipped']->id])
            ->and($ids('stage=passport'))->toEqualCanonicalizing([$p['entered']->id, $p['entered2']->id])
            ->and($ids("company_id={$p['fit']->company_id}"))->toBe([$p['fit']->id])
            ->and($ids("reference_id={$p['slipped']->reference_id}"))->toBe([$p['slipped']->id])
            ->and($ids('reference_type='.$p['fit']->reference->type->value.'&medical_status=all'))->toContain($p['fit']->id)
            ->and($ids("company_country_code={$p['fit']->company->country_code}"))->toContain($p['fit']->id);

        // The exact filters also still combine with the mode for other callers.
        expect(enteredIds("company_id={$p['entered']->company_id}"))->toBe([$p['entered']->id]);
    });
});
