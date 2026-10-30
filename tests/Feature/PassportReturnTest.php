<?php

use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportReturn;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A passport with the visa submitted (Visa → Pending), or with the flight
 * confirmed when $confirmed.
 *
 * @param  array<string, mixed>  $attributes
 */
function visaPendingPassport(array $attributes = [], bool $confirmed = false): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => 'online',
        ...$attributes,
    ]);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);

    if (! $confirmed) {
        PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::InProcess)->on('2026-04-03')->create(['reference_no' => null]);

        return $passport->fresh();
    }

    PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::Completed)->on('2026-04-03')
        ->create(['reference_no' => null, 'details' => ['issue_date' => '2026-04-03'], 'valid_until' => '2026-10-03']);
    PassportStepRecord::factory()->for($passport)->step('bmet')->status(StepStatus::Completed)->on('2026-04-04')
        ->create(['reference_no' => null, 'details' => ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25']]);
    PassportStepRecord::factory()->for($passport)->step('flight')->status(StepStatus::Completed)->flight('2026-05-20', '2026-04-28')->create();

    return $passport->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function returnPayload(array $overrides = []): array
{
    return [
        'return_date' => '2026-04-30',
        'receiver_name' => 'Rahim Uddin',
        'receiver_phone' => '01711000000',
        'receiver_id_no' => '1990123456789',
        'remarks' => 'Owner collected it',
        ...$overrides,
    ];
}

/**
 * @return list<int>
 */
function visaPendingIds(): array
{
    return test()->getJson('/api/data-entry/steps/visa/passports?tab=pending&per_page=100')->assertOk()->json('data.*.id');
}

describe('returning a passport', function () {
    it('lets the operations head return a passport at any step, keeping the step it was at', function () {
        $passport = visaPendingPassport();
        $head = User::factory()->operationHead()->create(['name' => 'Head One']);
        Sanctum::actingAs($head);

        expect(visaPendingIds())->toContain($passport->id);

        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())
            ->assertOk()
            ->assertJsonPath('data.returned.receiver_name', 'Rahim Uddin')
            ->assertJsonPath('data.returned.receiver_phone', '01711000000')
            ->assertJsonPath('data.returned.receiver_id_no', '1990123456789')
            ->assertJsonPath('data.returned.return_date', '2026-04-30')
            ->assertJsonPath('data.returned.stage', 'visa')
            ->assertJsonPath('data.returned.stage_label', 'Visa')
            ->assertJsonPath('data.returned.returned_by.name', 'Head One')
            ->assertJsonPath('data.current_stage', 'visa')
            ->assertJsonPath('data.stage_status', 'in_process')
            ->assertJsonPath('data.latest_status.text', 'Returned on 30-04-2026 to Rahim Uddin (01711000000)')
            ->assertJsonPath('data.can.return', false)
            ->assertJsonPath('data.can.undo_return', true);

        expect(visaPendingIds())->not->toContain($passport->id)
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs.visa.pending'))->toBe(0);
    });

    it('cannot return a passport once its flight is confirmed', function () {
        $passport = visaPendingPassport(confirmed: true);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")->assertJsonPath('data.can.return', false);
        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertForbidden();

        expect($passport->fresh()->current_return_id)->toBeNull()
            ->and($this->getJson('/api/data-entry/steps/flight/passports?tab=confirmed&per_page=100')->json('data.*.id'))
            ->toContain($passport->id);
    });

    it('takes passports out of the Passport Entry List and the medical lists, but not All Passports', function () {
        $entered = PassportEntry::factory()->create();
        $pending = PassportEntry::factory()->withMedicalSlip('2026-04-20')->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        foreach ([$entered, $pending] as $passport) {
            $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertOk();
        }

        expect($this->getJson('/api/data-entry/passports?mode=passport_entered')->json('data.*.id'))->not->toContain($entered->id)
            ->and($this->getJson('/api/data-entry/passports?mode=medical')->json('data.*.id'))->not->toContain($pending->id)
            ->and($this->getJson('/api/data-entry/medical/queue')->json('data.*.id'))->not->toContain($pending->id)
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.step2.pending'))->toBe(0);

        $overview = collect($this->getJson('/api/reports/passports?view=overview&medical_status=all')->assertOk()->json('data'));

        expect($overview->pluck('id')->all())->toContain($entered->id, $pending->id)
            ->and($overview->firstWhere('id', $entered->id)['returned']['receiver_name'])->toBe('Rahim Uddin')
            ->and($overview->firstWhere('id', $entered->id)['latest_status']['status'])->toBe('returned');
    });

    it('is for the operations head only', function (string $role) {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertForbidden();

        expect($passport->fresh()->current_return_id)->toBeNull();
    })->with(['dataEntry', 'admin', 'superAdmin', 'accounts']);

    it('needs the receiver, the phone and a return date that is not in the future', function () {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload([
            'receiver_name' => '  ',
            'receiver_phone' => '',
            'return_date' => '2026-05-02',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['receiver_name', 'receiver_phone', 'return_date']);

        // Today in Dhaka (UTC is still on the day before) is allowed; NID and remarks are optional.
        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload([
            'return_date' => '2026-05-01',
            'receiver_id_no' => '',
            'remarks' => null,
        ]))->assertOk()->assertJsonPath('data.returned.receiver_id_no', null);
    });

    it('cannot return a passport twice', function () {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertForbidden();

        expect(PassportReturn::query()->count())->toBe(1);
    });

    it('refuses every workflow change while the passport is returned', function () {
        $visa = visaPendingPassport();
        $entered = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson("/api/data-entry/passports/{$visa->id}/return", returnPayload())->assertOk();
        $this->postJson("/api/data-entry/passports/{$entered->id}/return", returnPayload())->assertOk();

        $this->postJson("/api/data-entry/passports/{$visa->id}/steps/visa/result", ['result' => 'rejected'])
            ->assertUnprocessable()->assertJsonPath('code', 'passport_returned');

        $this->postJson("/api/data-entry/passports/{$visa->id}/steps/flight/booking", ['booking_date' => '2026-05-20'])
            ->assertUnprocessable()->assertJsonPath('code', 'passport_returned');

        $this->patchJson("/api/data-entry/passports/{$entered->id}/medical-slip", ['medical_slip_date' => '2026-04-20'])
            ->assertUnprocessable()->assertJsonPath('code', 'passport_returned');

        expect($visa->fresh()->stage_status)->toBe('in_process')
            ->and($entered->fresh()->medical_slip_date)->toBeNull();
    });
});

describe('undo return', function () {
    it('puts the passport back exactly where it was and keeps the return in the history', function () {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create(['name' => 'Head One']));

        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertOk();

        $this->deleteJson("/api/data-entry/passports/{$passport->id}/return")
            ->assertOk()
            ->assertJsonPath('data.returned', null)
            ->assertJsonPath('data.current_stage', 'visa')
            ->assertJsonPath('data.stage_status', 'in_process')
            ->assertJsonPath('data.can.return', true)
            ->assertJsonPath('data.can.undo_return', false);

        expect(visaPendingIds())->toContain($passport->id);

        $return = PassportReturn::query()->sole();
        expect($return->undone_at)->not->toBeNull()
            ->and($return->undoer->name)->toBe('Head One');

        $history = collect($this->getJson("/api/data-entry/passports/{$passport->id}/history")->assertOk()->json('data'));
        expect($history->pluck('title')->all())->toContain('Passport returned (undone)', 'Return undone')
            ->and($history->firstWhere('key', "return-{$return->id}")['recorded_by']['name'])->toBe('Head One');

        // Nothing left to undo.
        $this->deleteJson("/api/data-entry/passports/{$passport->id}/return")->assertForbidden();
    });

    it('is for the operations head only', function () {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/return", returnPayload())->assertOk();

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->deleteJson("/api/data-entry/passports/{$passport->id}/return")->assertForbidden();

        expect($passport->fresh()->current_return_id)->not->toBeNull();
    });
});

describe('returned passports list', function () {
    it('lists the returned passports for every role, with undo for the operations head only', function (string $role, bool $canUndo) {
        $company = Company::factory()->create(['name' => 'Gulf Builders']);
        $returned = visaPendingPassport(['company_id' => $company->id, 'passport_name' => 'KARIM MIAH']);
        $other = visaPendingPassport(['passport_name' => 'SALMA BEGUM']);
        $inWorkflow = visaPendingPassport();

        Sanctum::actingAs(User::factory()->operationHead()->create(['name' => 'Head One']));
        $this->postJson("/api/data-entry/passports/{$returned->id}/return", returnPayload())->assertOk();
        $this->postJson("/api/data-entry/passports/{$other->id}/return", returnPayload(['return_date' => '2026-04-10']))->assertOk();

        Sanctum::actingAs(User::factory()->{$role}()->create());

        $rows = $this->getJson('/api/reports/returned-passports')->assertOk()->json('data');
        expect(array_column(array_column($rows, 'passport'), 'id'))->toBe([$returned->id, $other->id])
            ->and($rows[0])->toMatchArray([
                'receiver_name' => 'Rahim Uddin',
                'receiver_phone' => '01711000000',
                'receiver_id_no' => '1990123456789',
                'stage_label' => 'Visa',
                'return_date' => '2026-04-30',
                'can_undo' => $canUndo,
            ])
            ->and($rows[0]['company']['name'])->toBe('Gulf Builders')
            ->and($rows[0]['returned_by']['name'])->toBe('Head One')
            ->and(array_column(array_column($rows, 'passport'), 'id'))->not->toContain($inWorkflow->id);

        $ids = fn (string $query) => array_column(array_column($this->getJson("/api/reports/returned-passports?{$query}")->assertOk()->json('data'), 'passport'), 'id');
        expect($ids('q=karim'))->toBe([$returned->id])
            ->and($ids("company_id={$company->id}"))->toBe([$returned->id])
            ->and($ids('date_from=2026-04-01&date_to=2026-04-15'))->toBe([$other->id]);
    })->with([
        'operations head' => ['operationHead', true],
        'data entry' => ['dataEntry', false],
        'accounts' => ['accounts', false],
        'admin' => ['admin', false],
    ]);

    it('leaves undone returns out and downloads as CSV', function () {
        $undone = visaPendingPassport();
        $returned = visaPendingPassport(['passport_name' => 'KARIM MIAH']);
        Sanctum::actingAs(User::factory()->operationHead()->create());
        $this->postJson("/api/data-entry/passports/{$undone->id}/return", returnPayload())->assertOk();
        $this->deleteJson("/api/data-entry/passports/{$undone->id}/return")->assertOk();
        $this->postJson("/api/data-entry/passports/{$returned->id}/return", returnPayload())->assertOk();

        expect(array_column(array_column($this->getJson('/api/reports/returned-passports')->json('data'), 'passport'), 'id'))->toBe([$returned->id]);

        $csv = $this->get('/api/reports/returned-passports?export=csv')->assertOk()->streamedContent();
        expect($csv)->toContain('Receiver phone')
            ->toContain('KARIM MIAH')
            ->toContain('30-04-2026');
    });
});

describe('reports', function () {
    it('counts returned passports and no longer as in process', function () {
        $company = Company::factory()->create();
        $returned = visaPendingPassport(['company_id' => $company->id]);
        visaPendingPassport(['company_id' => $company->id]);
        Sanctum::actingAs(User::factory()->operationHead()->create());
        $this->postJson("/api/data-entry/passports/{$returned->id}/return", returnPayload())->assertOk();

        Sanctum::actingAs(User::factory()->admin()->create());
        $row = collect($this->getJson('/api/reports/companies?per_page=100')->assertOk()->json('data'))->firstWhere('id', $company->id);

        expect($row)->toMatchArray(['total' => 2, 'in_process' => 1, 'returned' => 1, 'calling_done' => 2]);
    });
});

describe('no deletes', function () {
    it('refuses deleting passports, medical records and step records, even to the operations head', function () {
        $passport = visaPendingPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->deleteJson("/api/data-entry/passports/{$passport->id}")->assertForbidden();
        $this->deleteJson("/api/data-entry/medicals/{$passport->current_medical_id}")->assertForbidden();
        $visa = PassportStepRecord::query()->where('passport_entry_id', $passport->id)->where('step_key', 'visa')->sole();
        $this->deleteJson("/api/data-entry/step-records/{$visa->id}")->assertForbidden();

        expect(PassportEntry::query()->whereKey($passport->id)->exists())->toBeTrue()
            ->and(MedicalRecord::query()->whereKey($passport->current_medical_id)->exists())->toBeTrue()
            ->and(PassportStepRecord::query()->whereKey($visa->id)->exists())->toBeTrue();
    });
});
