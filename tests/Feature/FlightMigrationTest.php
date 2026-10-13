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

it('moves legacy flight records to the new tabs, idempotently, without deleting anything', function () {
    $migration = 'database/migrations/2026_10_23_000001_flight_confirm_tabs.php';
    $passport = function (array $records) {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
            ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-03')->create(['valid_until' => '2026-10-03']);

        foreach ($records as $step => $status) {
            $factory = PassportStepRecord::factory()->for($passport)->step($step)->status($status)->on($step === 'bmet' ? '2026-04-04' : '2026-04-05');
            ($step === 'flight' ? $factory->flight('2026-05-20') : $factory)->create();
        }

        return $passport;
    };

    $booked = $passport(['bmet' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
    $departed = $passport(['bmet' => StepStatus::Completed, 'flight' => StepStatus::Completed]);
    $cancelled = $passport(['bmet' => StepStatus::Completed, 'flight' => StepStatus::Rejected]);
    $cancelledWithoutBmet = $passport(['bmet' => StepStatus::InProcess, 'flight' => StepStatus::Rejected]);
    $records = PassportStepRecord::query()->orderBy('id')->get(['id', 'status', 'step_date', 'details'])->toArray();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    // As stored before: the cancelled flights sat at flight / rejected.
    DB::table('passport_entries')->whereIn('id', [$cancelled->id, $cancelledWithoutBmet->id])->update(['current_stage' => 'flight', 'stage_status' => 'rejected']);

    $check = function () use ($booked, $departed, $cancelled, $cancelledWithoutBmet, $records) {
        $position = fn (PassportEntry $passport) => array_values((array) DB::table('passport_entries')->where('id', $passport->id)->first(['current_stage', 'stage_status']));

        expect($position($booked))->toBe(['flight', 'in_process'])
            ->and($position($departed))->toBe(['completed', null])
            ->and($position($cancelled))->toBe(['flight', 'waiting'])
            ->and($position($cancelledWithoutBmet))->toBe(['bmet', 'in_process'])
            // Every record kept as it was.
            ->and(PassportStepRecord::query()->orderBy('id')->get(['id', 'status', 'step_date', 'details'])->toArray())->toBe($records);
    };

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
});
