<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (12:00).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 12:00', 'Asia/Dhaka')->utc());
});

/**
 * A permitted passport (fit medical, calling completed on 2026-04-02), with
 * the visa submitted on 2026-04-10 unless $visa is null.
 */
function visaPassport(?StepStatus $visa = StepStatus::InProcess): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['passport_expiry_date' => '2035-01-01', 'entry_mode' => 'online']);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);

    if ($visa !== null) {
        PassportStepRecord::factory()->for($passport)->step('visa')->status($visa)->on('2026-04-10')
            ->create($visa === StepStatus::Completed
                ? ['reference_no' => null, 'details' => ['issue_date' => '2026-04-20'], 'valid_until' => '2026-10-20']
                : ['reference_no' => null]);
    }

    return $passport->fresh();
}

function visaResultUrl(PassportEntry $passport): string
{
    return "/api/data-entry/passports/{$passport->id}/steps/visa/result";
}

it('leaves the mode out of the visa rows', function () {
    visaPassport();
    visaPassport(StepStatus::Completed);
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    foreach (['pending', 'issued'] as $tab) {
        $row = $this->getJson("/api/data-entry/steps/visa/passports?tab={$tab}")->assertOk()->json('data.0');

        expect($row)->not->toHaveKeys(['entry_mode', 'mode'])
            ->and($row['previous_record'])->not->toHaveKeys(['entry_mode', 'mode']);
    }
});

it('labels the calling expiry and the visa issued form fields, the visa number last', function () {
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $steps = collect($this->getJson('/api/data-entry/workflow/config')->assertOk()->json('data.steps'))->keyBy('key');
    $resultFields = collect($steps['visa']['fields'])->where('phase', 'result')->values();

    expect(collect($steps['calling']['fields'])->firstWhere('name', 'valid_until')['label'])->toBe('Calling expiry')
        ->and(json_encode($steps->all()))->not->toContain('permit expiry')
        ->and($resultFields->pluck('label')->all())->toBe(['Issue date', 'Expired date', 'Visa number'])
        ->and($resultFields->pluck('required_for')->all())->toBe([['completed'], ['completed'], []])
        ->and($resultFields->last())->toMatchArray(['name' => 'reference_no', 'max' => 50]);
});

it('issues the visa with its issue date, expired date and optional visa number', function () {
    $passport = visaPassport();
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->postJson(visaResultUrl($passport), ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2027-04-24'])
        ->assertOk()
        ->assertJsonPath('data.status_label', 'Visa issued')
        // The issue date is the record's result date.
        ->assertJsonPath('data.step_date', '2026-04-25')
        ->assertJsonPath('data.details.issue_date', '2026-04-25')
        ->assertJsonPath('data.details.start_date', '2026-04-10')
        ->assertJsonPath('data.valid_until', '2027-04-24')
        ->assertJsonPath('data.reference_no', null)
        ->assertJsonPath('passport.current_stage', 'bmet');

    expect($passport->fresh()->stage_status_date->toDateString())->toBe('2026-04-25');
});

it('validates the visa issued fields', function (array $body, string $field, ?string $message = null) {
    $passport = visaPassport();
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->postJson(visaResultUrl($passport), ['result' => 'completed', ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($message ? [$field => $message] : $field);

    expect(PassportStepRecord::query()->where('step_key', 'visa')->sole()->status)->toBe(StepStatus::InProcess);
})->with([
    'no issue date' => [['valid_until' => '2026-10-20'], 'details.issue_date'],
    'issue date in the future' => [['issue_date' => '2026-05-02', 'valid_until' => '2026-10-20'], 'details.issue_date', 'The issue date cannot be in the future.'],
    'no expired date' => [['issue_date' => '2026-04-20'], 'valid_until'],
    'expired date before the issue date' => [['issue_date' => '2026-04-20', 'valid_until' => '2026-04-19'], 'valid_until', 'The expired date must be after the issue date.'],
    'expired date on the issue date' => [['issue_date' => '2026-04-20', 'valid_until' => '2026-04-20'], 'valid_until'],
    'visa number too long' => [['issue_date' => '2026-04-20', 'valid_until' => '2026-10-20', 'reference_no' => str_repeat('V', 51)], 'reference_no'],
]);

it('keeps the rejected result, Apply again and the warnings', function () {
    $passport = visaPassport();
    $expiring = tap(visaPassport(), fn (PassportEntry $p) => $p->forceFill(['passport_expiry_date' => '2026-10-01'])->saveQuietly());
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $this->postJson(visaResultUrl($passport), ['result' => 'rejected', 'result_date' => '2026-04-22'])
        ->assertOk()
        ->assertJsonPath('data.status_label', 'Rejected')
        ->assertJsonPath('data.step_date', '2026-04-22');
    $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", ['step_date' => '2026-04-28'])->assertCreated();

    $this->postJson(visaResultUrl($expiring), ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2026-09-01'])
        ->assertOk()
        ->assertJsonPath('warnings.*.code', ['passport_expires_soon']);
});

it('shows an issued visa in Visa issued, BMET Pending and Flight Pending at once', function () {
    $passport = visaPassport();
    Sanctum::actingAs(User::factory()->dataEntry()->create());
    $ids = fn (string $step, string $tab) => $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}")->json('data.*.id');

    $this->postJson(visaResultUrl($passport), ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertOk();

    $summary = $this->getJson('/api/data-entry/workflow/summary')->json('data');

    expect($ids('visa', 'issued'))->toBe([$passport->id])
        ->and($ids('bmet', 'pending'))->toBe([$passport->id])
        ->and($ids('flight', 'pending'))->toBe([$passport->id])
        ->and($ids('visa', 'pending'))->toBe([])
        ->and($summary['stages']['visa'])->toBe(['total' => 0, 'waiting' => 0])
        ->and($summary['stages']['bmet'])->toBe(['total' => 1, 'waiting' => 1])
        ->and($summary['stages']['flight'])->toBe(['total' => 1, 'waiting' => 1])
        ->and($summary['step_tabs']['flight'])->toBe(['pending' => 1, 'confirmed' => 0])
        ->and($this->getJson('/api/data-entry/passports?view=overview&medical_status=all')->json('data.0.latest_status.text'))
        ->toBe('Visa issued - BMET and flight pending');
});

it('lets only data entry record the visa result, not admins or accounts', function (string $role, int $status) {
    $passport = visaPassport();
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->postJson(visaResultUrl($passport), ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertStatus($status);
})->with([
    'data entry' => ['dataEntry', 200],
    'admin' => ['admin', 403],
    'super admin' => ['superAdmin', 403],
    'accounts' => ['accounts', 403],
]);
