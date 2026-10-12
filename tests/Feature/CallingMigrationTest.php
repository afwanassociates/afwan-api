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

it('reshapes existing calling records, idempotently and reversibly', function () {
    $migration = 'database/migrations/2026_10_20_000001_reshape_calling_records.php';
    $passport = function () {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();

        return $passport;
    };

    // Approved before: no receive or issued date.
    $completed = PassportStepRecord::factory()->for($passport())->step('calling')->on('2026-04-12')
        ->create(['valid_until' => '2026-10-01', 'details' => null]);
    // A calling date entered.
    $inProcess = PassportStepRecord::factory()->for($passport())->step('calling')->status(StepStatus::InProcess)->on('2026-04-15')->create(['details' => null]);
    // Rejected before.
    $rejectedPassport = $passport();
    $rejected = PassportStepRecord::factory()->for($rejectedPassport)->step('calling')->status(StepStatus::Rejected)->on('2026-04-08')->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    // As stored before: the rejected passport sat at calling / rejected.
    DB::table('passport_entries')->where('id', $rejectedPassport->id)->update(['current_stage' => 'calling', 'stage_status' => 'rejected']);

    $check = function () use ($completed, $inProcess, $rejected, $rejectedPassport) {
        $completedRow = DB::table('passport_step_records')->where('id', $completed->id)->first();

        expect(json_decode($completedRow->details, true))->toBe(['receive_date' => '2026-04-12'])
            ->and($completedRow->valid_until)->toBe('2026-10-01')
            ->and($completedRow->status)->toBe('completed')
            ->and(DB::table('passport_step_records')->where('id', $inProcess->id)->value('details'))->toBeNull()
            ->and(DB::table('passport_step_records')->where('id', $inProcess->id)->value('status'))->toBe('in_process')
            // Kept in the history...
            ->and(DB::table('passport_step_records')->where('id', $rejected->id)->value('status'))->toBe('rejected')
            // ...but the passport is back to Pending without a calling date.
            ->and((array) DB::table('passport_entries')->where('id', $rejectedPassport->id)->first(['current_stage', 'stage_status']))
            ->toBe(['current_stage' => 'calling', 'stage_status' => 'waiting']);
    };

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();

    // Rolling back removes only the receive date it filled in; running it
    // again gives the same result.
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    expect(DB::table('passport_step_records')->where('id', $completed->id)->value('details'))->toBeNull();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
});
