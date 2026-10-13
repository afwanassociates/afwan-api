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

it('moves older flight records to booking and confirmed dates, idempotently and reversibly', function () {
    $migration = 'database/migrations/2026_10_25_000001_flight_booking_and_confirm.php';
    $passport = function (?StepStatus $bmet = StepStatus::Completed) {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['entry_mode' => 'online']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
            ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-03')->create(['details' => ['issue_date' => '2026-04-03'], 'valid_until' => '2026-10-03']);

        if ($bmet !== null) {
            PassportStepRecord::factory()->for($passport)->step('bmet')->status($bmet)->on('2026-04-04')->create();
        }

        return $passport;
    };
    // Records as stored before: a departure date and flight details.
    $older = fn (PassportEntry $passport, StepStatus $status, string $date, array $details = []) => PassportStepRecord::factory()->for($passport)
        ->step('flight')->status($status)->on($date)
        ->create(['reference_no' => null, 'details' => ['airline' => 'Sample Air', 'departure_date' => '2026-05-20', ...$details]]);

    $bookedPassport = $passport();
    $booked = $older($bookedPassport, StepStatus::InProcess, '2026-04-10');
    $departedPassport = $passport();
    $departed = $older($departedPassport, StepStatus::Completed, '2026-04-28');
    // Confirmed earlier with its booking date already kept.
    $keptPassport = $passport();
    $kept = $older($keptPassport, StepStatus::Completed, '2026-04-29', ['booking_date' => '2026-04-15']);
    $cancelledPassport = $passport();
    $cancelled = $older($cancelledPassport, StepStatus::Rejected, '2026-04-12');
    // Visa issued, BMET not started, no flight record.
    $visaOnly = $passport(null);
    $recordCount = PassportStepRecord::withTrashed()->count();

    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    // As stored before: the cancelled flight sat at flight / rejected.
    DB::table('passport_entries')->where('id', $cancelledPassport->id)->update(['current_stage' => 'flight', 'stage_status' => 'rejected']);

    $details = fn (PassportStepRecord $record) => json_decode((string) DB::table('passport_step_records')->where('id', $record->id)->value('details'), true);
    $position = fn (PassportEntry $passport) => array_values((array) DB::table('passport_entries')->where('id', $passport->id)->first(['current_stage', 'stage_status']));

    $check = function () use ($details, $position, $booked, $bookedPassport, $departed, $departedPassport, $kept, $cancelled, $cancelledPassport, $visaOnly, $recordCount) {
        expect($details($booked))->toMatchArray(['booking_date' => '2026-04-10', 'departure_date' => '2026-05-20', 'airline' => 'Sample Air'])
            ->and($details($booked))->not->toHaveKey('confirmed_date')
            ->and($position($bookedPassport))->toBe(['flight', 'in_process'])
            ->and($details($departed))->toMatchArray(['booking_date' => '2026-04-28', 'confirmed_date' => '2026-04-28'])
            ->and($position($departedPassport))->toBe(['completed', null])
            ->and($details($kept))->toMatchArray(['booking_date' => '2026-04-15', 'confirmed_date' => '2026-04-29'])
            // Kept in the history, untouched; the passport is back in Pending.
            ->and($details($cancelled))->toBe(['airline' => 'Sample Air', 'departure_date' => '2026-05-20'])
            ->and(DB::table('passport_step_records')->where('id', $cancelled->id)->value('status'))->toBe('rejected')
            ->and($position($cancelledPassport))->toBe(['flight', 'waiting'])
            // In BMET Pending and Flight Pending.
            ->and($position($visaOnly))->toBe(['bmet', 'waiting'])
            // Nothing deleted.
            ->and(PassportStepRecord::withTrashed()->count())->toBe($recordCount);
    };

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();

    // Rolling back removes only the dates it filled in.
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    expect($details($booked))->toBe(['airline' => 'Sample Air', 'departure_date' => '2026-05-20'])
        ->and($details($departed))->toBe(['airline' => 'Sample Air', 'departure_date' => '2026-05-20'])
        ->and($details($kept))->toMatchArray(['booking_date' => '2026-04-15'])
        ->and($details($kept))->not->toHaveKey('confirmed_date');

    // Running it again gives the same result.
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
    $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $check();
});
