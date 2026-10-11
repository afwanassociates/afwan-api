<?php

use App\Models\PassportEntry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| This test rolls migrations back and forward. Schema changes end MySQL's
| test transaction, so make the next test start from a fresh database.
*/
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('adds the scan columns reversibly, leaving existing passports without a scan', function () {
    $migration = 'database/migrations/2026_10_17_000001_add_scan_to_passport_entries_table.php';
    $columns = ['scan_path', 'scan_original_name', 'scan_mime', 'scan_size', 'scan_uploaded_at'];
    $passport = PassportEntry::factory()->withoutScan()->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();

    expect(Schema::hasColumns('passport_entries', $columns))->toBeFalse()
        ->and(DB::table('passport_entries')->where('id', $passport->id)->exists())->toBeTrue();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasColumns('passport_entries', $columns))->toBeTrue()
        ->and(PassportEntry::find($passport->id)->hasScan())->toBeFalse();
});

it('drops the medical slip number reversibly, keeping the slip date and centre', function () {
    $migration = 'database/migrations/2026_10_17_000002_drop_medical_slip_no_from_passport_entries_table.php';
    $passport = PassportEntry::factory()->withMedicalSlip('2026-04-15')->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    expect(Schema::hasColumn('passport_entries', 'medical_slip_no'))->toBeTrue();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('passport_entries', 'medical_slip_no'))->toBeFalse()
        ->and(Schema::hasColumns('passport_entries', ['medical_slip_date', 'medical_center_id']))->toBeTrue()
        ->and(PassportEntry::find($passport->id)->medical_slip_date->toDateString())->toBe('2026-04-15');
});
