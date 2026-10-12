<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| This test rolls a migration back and forward. Schema changes end MySQL's
| test transaction, so make the next test start from a fresh database.
*/
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('backfills issue dates and puts unmarked fit passports in the Medical Fit tab, idempotently and reversibly', function () {
    $migration = 'database/migrations/2026_10_18_000001_add_issue_date_and_entry_mode.php';

    // Fit, no calling record: will show as "not marked".
    $waiting = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create();
    $record = MedicalRecord::factory()->for($waiting)->on('2026-04-01')->create(['valid_until' => '2026-09-09']);
    // Fit with Calling already in process: keeps going without a mark.
    $started = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create();
    MedicalRecord::factory()->for($started)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($started)->step('calling')->status(StepStatus::InProcess)->on('2026-04-05')->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('passport_entries', 'entry_mode'))->toBeFalse()
        ->and(Schema::hasColumn('medical_records', 'issue_date'))->toBeFalse()
        // The old meaning: fit goes straight to Calling.
        ->and(DB::table('passport_entries')->where('id', $waiting->id)->value('current_stage'))->toBe('calling');

    $check = function () use ($waiting, $started, $record) {
        expect(DB::table('medical_records')->where('id', $record->id)->first(['issue_date', 'valid_until']))
            ->issue_date->toBe('2026-04-01')
            ->valid_until->toBe('2026-09-09')
            ->and((array) DB::table('passport_entries')->where('id', $waiting->id)->first(['entry_mode', 'current_stage', 'stage_status']))
            ->toBe(['entry_mode' => null, 'current_stage' => 'medical', 'stage_status' => 'fit'])
            ->and((array) DB::table('passport_entries')->where('id', $started->id)->first(['entry_mode', 'current_stage', 'stage_status']))
            ->toBe(['entry_mode' => null, 'current_stage' => 'calling', 'stage_status' => 'in_process']);
    };

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();

    // Running it again gives the same result.
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
});
