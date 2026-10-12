<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/*
| This test re-runs a migration. Migrations end MySQL's test transaction, so
| make the next test start from a fresh database.
*/
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('converts Key-in marks to Online, idempotently', function () {
    $migration = 'database/migrations/2026_10_19_000001_convert_keyin_entry_mode_to_online.php';

    $keyin = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
    MedicalRecord::factory()->for($keyin)->on('2026-04-01')->create();
    $online = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
    MedicalRecord::factory()->for($online)->on('2026-04-01')->create();
    $unmarked = PassportEntry::factory()->create();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();

    // As stored before Key-in was removed.
    DB::table('passport_entries')->where('id', $keyin->id)->update(['entry_mode' => 'keyin']);

    $modes = fn () => DB::table('passport_entries')->orderBy('id')->pluck('entry_mode', 'id')->all();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($modes())->toBe([$keyin->id => 'online', $online->id => 'online', $unmarked->id => null])
        // It keeps its place in Calling and reads as a valid mark.
        ->and(PassportEntry::find($keyin->id))
        ->entry_mode->value->toBe('online')
        ->current_stage->toBe('calling');

    // Running it again changes nothing; rolling back cannot restore Key-in.
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($modes())->toBe([$keyin->id => 'online', $online->id => 'online', $unmarked->id => null]);
});
