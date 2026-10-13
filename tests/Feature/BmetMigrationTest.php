<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/*
| This test re-runs a migration. Migrations end MySQL's test transaction, so
| make the next test start from a fresh database.
*/
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('moves legacy BMET records to the Pending and BMET cleared tabs, idempotently, without deleting anything', function () {
    $migration = 'database/migrations/2026_10_24_000001_bmet_milestones.php';
    $passport = function (StepStatus $bmet, array $details = []) {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
            ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-03')->create(['valid_until' => '2026-10-03']);
        PassportStepRecord::factory()->for($passport)->step('bmet')->status($bmet)->on('2026-04-04')->create(['details' => $details ?: null]);

        return $passport;
    };

    // Submitted, with its training and finger dates already in details.
    $submitted = $passport(StepStatus::InProcess, ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25']);
    $cleared = $passport(StepStatus::Completed);
    $rejected = $passport(StepStatus::Rejected);
    $records = PassportStepRecord::query()->orderBy('id')->get(['id', 'status', 'step_date', 'reference_no', 'details'])->toArray();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    // As stored before: the rejected BMET sat at bmet / rejected.
    DB::table('passport_entries')->where('id', $rejected->id)->update(['current_stage' => 'bmet', 'stage_status' => 'rejected']);

    $check = function () use ($submitted, $cleared, $rejected, $records) {
        $position = fn (PassportEntry $passport) => array_values((array) DB::table('passport_entries')->where('id', $passport->id)->first(['current_stage', 'stage_status']));

        expect($position($submitted))->toBe(['bmet', 'in_process'])
            ->and($position($cleared))->toBe(['flight', 'waiting'])
            ->and($position($rejected))->toBe(['bmet', 'waiting'])
            // Every record kept as it was.
            ->and(PassportStepRecord::query()->orderBy('id')->get(['id', 'status', 'step_date', 'reference_no', 'details'])->toArray())->toBe($records);
    };

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
});
