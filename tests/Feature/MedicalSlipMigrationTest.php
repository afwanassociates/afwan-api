<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| This test rolls a migration back and forward. Schema changes end MySQL's
| test transaction, so make the next test start from a fresh database.
*/
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

/**
 * Rolled back by path, so later migrations do not get in the way.
 */
const SLIP_MIGRATION = 'database/migrations/2026_10_12_000001_move_medical_slip_to_passport_entries_table.php';

it('moves the slip to passports, keeps results, and is reversible and idempotent', function () {
    $this->artisan('migrate:rollback', ['--path' => SLIP_MIGRATION, '--force' => true])->assertSuccessful();

    expect(Schema::hasColumn('medical_records', 'slip_date'))->toBeTrue()
        ->and(Schema::hasColumn('passport_entries', 'medical_slip_date'))->toBeFalse();

    // Data as it was before the migration.
    $user = User::factory()->dataEntry()->create();
    $reference = Reference::factory()->create();
    $company = Company::factory()->create();
    $center = DB::table('medical_centers')->where('name', 'Meem Medical Center')->value('id');

    $passport = fn (string $number, array $values = []) => DB::table('passport_entries')->insertGetId([
        'entry_no' => PassportEntry::nextEntryNo(),
        'passport_name' => 'SAMPLE NAME',
        'passport_number' => $number,
        'reference_id' => $reference->id,
        'company_id' => $company->id,
        'passport_received_date' => '2026-01-15',
        'current_stage' => 'medical',
        'stage_status' => 'pending',
        'stage_status_date' => '2026-01-15',
        'created_at' => now(),
        'updated_at' => now(),
        ...$values,
    ]);
    $medical = fn (int $passportId, string $date, array $slip = []) => DB::table('medical_records')->insertGetId([
        'passport_entry_id' => $passportId,
        'medical_date' => $date,
        'result' => 'fit',
        'valid_until' => Carbon::parse($date)->addMonthsNoOverflow(3)->toDateString(),
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$slip,
    ]);

    // A: a fit result with its slip on the record.
    $a = $passport('AA1111111');
    $recordA = $medical($a, '2026-04-01', ['slip_date' => '2026-03-25', 'slip_no' => 'MG-1', 'medical_center_id' => $center]);
    DB::table('passport_entries')->where('id', $a)->update(['current_medical_id' => $recordA, 'current_stage' => 'calling', 'stage_status' => 'waiting']);

    // B: "pending" with no medical and no slip.
    $b = $passport('BB2222222');

    // C: the slip is on an older record; the current one has none.
    $c = $passport('CC3333333');
    $medical($c, '2026-03-01', ['slip_date' => '2026-02-25', 'slip_no' => 'MG-3']);
    $recordC = $medical($c, '2026-04-10');
    DB::table('passport_entries')->where('id', $c)->update(['current_medical_id' => $recordC, 'current_stage' => 'calling', 'stage_status' => 'waiting']);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $row = fn (int $id) => DB::table('passport_entries')->where('id', $id)
        ->first(['medical_slip_date', 'medical_slip_no', 'medical_center_id', 'current_medical_id', 'current_stage', 'stage_status']);

    expect(Schema::hasColumn('medical_records', 'slip_date'))->toBeFalse()
        ->and((array) $row($a))->toBe([
            'medical_slip_date' => '2026-03-25', 'medical_slip_no' => 'MG-1', 'medical_center_id' => $center,
            // Fit but not marked Online, and Calling not started.
            'current_medical_id' => $recordA, 'current_stage' => 'medical', 'stage_status' => 'fit',
        ])
        // Pending without a slip date becomes not_started.
        ->and((array) $row($b))->toMatchArray(['medical_slip_date' => null, 'current_stage' => 'passport', 'stage_status' => 'not_started'])
        ->and((array) $row($c))->toMatchArray(['medical_slip_date' => '2026-02-25', 'medical_slip_no' => 'MG-3', 'current_medical_id' => $recordC, 'current_stage' => 'medical'])
        ->and(DB::table('medical_records')->count())->toBe(3);

    // Reversible: the slip goes back onto each passport's current record.
    $this->artisan('migrate:rollback', ['--path' => SLIP_MIGRATION, '--force' => true])->assertSuccessful();

    expect((array) DB::table('medical_records')->where('id', $recordA)->first(['slip_date', 'slip_no', 'medical_center_id']))
        ->toBe(['slip_date' => '2026-03-25', 'slip_no' => 'MG-1', 'medical_center_id' => $center])
        ->and(DB::table('medical_records')->where('id', $recordC)->value('slip_no'))->toBe('MG-3')
        ->and((array) DB::table('passport_entries')->where('id', $b)->first(['current_stage', 'stage_status']))
        ->toBe(['current_stage' => 'medical', 'stage_status' => 'pending']);

    // Running it again gives the same result.
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect((array) $row($a))->toMatchArray(['medical_slip_date' => '2026-03-25', 'medical_slip_no' => 'MG-1', 'medical_center_id' => $center])
        ->and((array) $row($b))->toMatchArray(['current_stage' => 'passport', 'stage_status' => 'not_started'])
        ->and((array) $row($c))->toMatchArray(['medical_slip_date' => '2026-02-25', 'medical_slip_no' => 'MG-3']);
});
