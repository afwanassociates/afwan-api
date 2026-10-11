<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
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

it('backfills the medical date from the current result, idempotently and reversibly', function () {
    $migration = 'database/migrations/2026_10_16_000001_add_medical_date_to_passport_entries_table.php';

    // A passport re-tested: an older unfit and a current fit result.
    $retested = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create();
    MedicalRecord::factory()->for($retested)->unfit()->on('2026-02-01')->create();
    MedicalRecord::factory()->for($retested)->on('2026-03-15')->create();
    // Pending: slip date but no result. Not started: neither.
    $pending = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create();
    $notStarted = PassportEntry::factory()->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    expect(Schema::hasColumn('passport_entries', 'medical_date'))->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $dates = fn () => DB::table('passport_entries')->orderBy('id')->pluck('medical_date', 'id')->all();

    expect($dates())->toBe([
        $retested->id => '2026-03-15',
        $pending->id => null,
        $notStarted->id => null,
    ]);

    // Running it again changes nothing.
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($dates())->toBe([
        $retested->id => '2026-03-15',
        $pending->id => null,
        $notStarted->id => null,
    ])->and(MedicalRecord::count())->toBe(2);
});
