<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A permitted passport with a visa issued on 2026-04-03, BMET cleared on
 * 2026-04-04 and, with $flightDone, a confirmed flight.
 */
function passportPastBmet(string $name, bool $flightDone): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_name' => $name,
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => 'online',
    ]);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
    PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::Completed)->on('2026-04-03')
        ->create(['reference_no' => 'V-1', 'details' => ['issue_date' => '2026-04-03', 'start_date' => '2026-03-30'], 'valid_until' => '2026-10-03']);
    PassportStepRecord::factory()->for($passport)->step('bmet')->status(StepStatus::Completed)->on('2026-04-04')
        ->create(['reference_no' => null, 'details' => ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25']]);

    if ($flightDone) {
        PassportStepRecord::factory()->for($passport)->step('flight')->status(StepStatus::Completed)->flight('2026-04-20', '2026-04-28')->create();
    }

    return $passport->fresh();
}

/**
 * @return array{list: list<int>, count: int}
 */
function afterFlightTab(string $step, string $tab): array
{
    $response = test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100")->assertOk();

    return ['list' => $response->json('data.*.id'), 'count' => $response->json("tab_counts.{$tab}")];
}

describe('Visa issued and BMET cleared after the flight is confirmed', function () {
    it('leaves the passport out of both tabs (and their counts) once the flight is confirmed', function () {
        $waiting = passportPastBmet('MD RAHIM', flightDone: false);
        $flown = passportPastBmet('MD KARIM', flightDone: true);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($flown->current_stage)->toBe('completed')
            ->and(afterFlightTab('visa', 'issued'))->toBe(['list' => [$waiting->id], 'count' => 1])
            ->and(afterFlightTab('bmet', 'cleared'))->toBe(['list' => [$waiting->id], 'count' => 1])
            // Still listed under Flight done.
            ->and(afterFlightTab('flight', 'confirmed')['list'])->toBe([$flown->id]);

        expect($this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs'))
            ->toMatchArray(['visa' => ['pending' => 0, 'issued' => 1, 'rejected' => 0], 'bmet' => ['pending' => 0, 'cleared' => 1]]);
    });
});

describe('step tab CSV export', function () {
    it('downloads the Visa issued tab with its dates, same filters as the list', function () {
        passportPastBmet('MD RAHIM', flightDone: false);
        passportPastBmet('MD SALAM', flightDone: false);
        passportPastBmet('MD KARIM', flightDone: true);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $response = $this->get('/api/data-entry/steps/visa/passports?tab=issued&search=rahim&export=csv')->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('visa-issued-01-05-2026.csv');
        $lines = array_map('str_getcsv', explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        expect($lines[0])->toBe(['No.', 'Passport name', 'Passport number', 'Company', 'Reference', 'Status', 'Visa submitted date', 'Issue date', 'Expired date', 'Visa number'])
            ->and($lines)->toHaveCount(2)
            ->and($lines[1][1])->toBe('MD RAHIM')
            ->and(array_slice($lines[1], 6))->toBe(['30-03-2026', '03-04-2026', '03-10-2026', 'V-1']);
    });

    it('downloads the BMET cleared tab with the visa and BMET dates', function () {
        passportPastBmet('MD RAHIM', flightDone: false);
        passportPastBmet('MD KARIM', flightDone: true);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $csv = ltrim($this->get('/api/data-entry/steps/bmet/passports?tab=cleared&export=csv')->assertOk()->streamedContent(), "\xEF\xBB\xBF");
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));

        expect($lines[0])->toBe(['No.', 'Passport name', 'Passport number', 'Company', 'Reference', 'Status', 'Visa issue date', 'Visa expired date', 'Training date', 'Finger date', 'Clearance date'])
            ->and($lines)->toHaveCount(2)
            ->and($lines[1][1])->toBe('MD RAHIM')
            ->and(array_slice($lines[1], 6, 4))->toBe(['03-04-2026', '03-10-2026', '20-03-2026', '25-03-2026']);
    });

    it('rejects an unknown export format', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/steps/visa/passports?tab=issued&export=pdf')->assertUnprocessable()->assertJsonValidationErrors('export');
    });
});

describe('step list Filter by company / Filter by reference', function () {
    it('filters the rows and the tab counts by company_id and reference_id', function () {
        $rahim = passportPastBmet('MD RAHIM', flightDone: false);
        $salam = passportPastBmet('MD SALAM', flightDone: false);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $byReference = $this->getJson("/api/data-entry/steps/bmet/passports?tab=cleared&reference_id={$rahim->reference_id}")->assertOk();
        expect($byReference->json('data.*.id'))->toBe([$rahim->id])
            ->and($byReference->json('tab_counts.cleared'))->toBe(1);

        expect($this->getJson("/api/data-entry/steps/visa/passports?tab=issued&company_id={$salam->company_id}")->json('data.*.id'))->toBe([$salam->id])
            ->and($this->getJson("/api/data-entry/steps/visa/passports?tab=issued&company_id={$salam->company_id}&reference_id={$rahim->reference_id}")->json('data'))->toBe([]);

        $this->getJson('/api/data-entry/steps/visa/passports?company_id=999&reference_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_id', 'reference_id']);
    });
});
