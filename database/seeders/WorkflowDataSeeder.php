<?php

namespace Database\Seeders;

use App\Enums\EntryMode;
use App\Enums\StepStatus;
use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\LocalDate;
use Illuminate\Database\Seeder;

class WorkflowDataSeeder extends Seeder
{
    /**
     * Development sample data: calling, visa, BMET and flight records for the
     * sample passports with a valid fit medical, covering every stage and
     * status (waiting, in process, rejected then recorded again, completed,
     * flights booked, pending and confirmed, expiring and expired visas). Passports with
     * a pending, unfit or expired medical are left as they are.
     */
    public function run(WorkflowService $workflow): void
    {
        if (app()->isProduction()) {
            $this->command?->error('WorkflowDataSeeder creates sample workflow data and will not run in production.');

            return;
        }

        // Only add records once, so running the seeder again does not pile them up.
        if (PassportStepRecord::withTrashed()->exists()) {
            $this->command?->info('Step records already exist; skipped.');

            return;
        }

        $clerk = User::query()->where('role', UserRole::DataEntry)->first();

        if ($clerk === null) {
            $this->command?->warn('No data entry user found; run StaffUserSeeder first.');

            return;
        }

        $today = LocalDate::today();

        $passports = PassportEntry::query()
            ->with('currentMedical')
            ->orderBy('id')
            ->get()
            ->filter(fn (PassportEntry $passport) => $passport->medicalStatus()->isPassed())
            ->values();

        $scenarios = [
            'calling_legacy_date_entered', 'calling_pending', 'calling_permitted',
            'visa_submitted', 'visa_rejected', 'visa_issued_expiring',
            'bmet_in_progress', 'bmet_cleared_visa_expired', 'flight_pending', 'flight_confirmed',
        ];

        foreach ($passports as $index => $passport) {
            $scenario = $scenarios[$index % count($scenarios)];

            // Marked Online first: only then can Calling start.
            $workflow->updateEntryMode($passport, EntryMode::Online);

            // One day apart after the medical, never after today.
            $base = LocalDate::parse($passport->currentMedical->medical_date);
            $day = fn (int $days): string => $base->addDays($days)->min($today)->toDateString();

            $record = fn (string $step, StepStatus $status, int $days, array $extra = []) => $workflow->recordStep($passport, $step, [
                'status' => $status,
                'step_date' => $day($days),
                'reference_no' => $status === StepStatus::Completed && $step === 'visa' ? strtoupper($step).'-'.(1000 + $passport->id) : null,
                ...$extra,
            ], $clerk);

            // Permitted: received a day after the calling date, valid for 6 months.
            $permit = fn () => [
                'details' => ['receive_date' => $day(1), 'issue_date' => $day(1)],
                'valid_until' => $today->addMonths(6)->toDateString(),
            ];

            match ($scenario) {
                // An older record that only had a calling date (still Pending).
                'calling_legacy_date_entered' => $record('calling', StepStatus::InProcess, 1),
                // No record yet: Pending.
                'calling_pending' => null,
                'calling_permitted' => $record('calling', StepStatus::Completed, 1, $permit()),
                default => null,
            };

            if (in_array($scenario, ['visa_submitted', 'visa_rejected', 'visa_issued_expiring', 'bmet_in_progress', 'bmet_cleared_visa_expired', 'flight_pending', 'flight_confirmed'], true)) {
                $record('calling', StepStatus::Completed, 1, $permit());
            }

            match ($scenario) {
                'visa_submitted' => $record('visa', StepStatus::InProcess, 2),
                'visa_rejected' => $record('visa', StepStatus::Rejected, 2, ['remarks' => 'Sample remark: visa refused.']),
                // Within the 30-day warning period.
                'visa_issued_expiring' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->addDays(20)->toDateString(), 'details' => ['issue_date' => $day(2)]]),
                'bmet_cleared_visa_expired' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->subDay()->toDateString(), 'details' => ['issue_date' => $day(2)]]),
                'bmet_in_progress', 'flight_pending', 'flight_confirmed' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->addMonths(3)->toDateString(), 'details' => ['issue_date' => $day(2)]]),
                default => null,
            };

            match ($scenario) {
                // Training done, finger still to do.
                'bmet_in_progress' => $record('bmet', StepStatus::InProcess, 3, ['details' => ['training_certificate_date' => $day(3)]]),
                'bmet_cleared_visa_expired', 'flight_pending', 'flight_confirmed' => $record('bmet', StepStatus::Completed, 3, ['details' => ['training_certificate_date' => $day(3), 'finger_date' => $day(3)]]),
                default => null,
            };

            match ($scenario) {
                // Booked for a flight in three weeks while BMET is still open.
                'bmet_in_progress' => $workflow->saveBooking($passport, 'flight', $today->addDays(21)->toDateString(), $clerk),
                // BMET cleared, no booking yet: Flight Pending.
                'flight_pending' => null,
                // Booked for a flight in two weeks and confirmed today.
                'flight_confirmed' => tap($workflow->saveBooking($passport, 'flight', $today->addDays(14)->toDateString(), $clerk), fn () => $workflow->confirm($passport, 'flight', $clerk)),
                default => null,
            };
        }
    }
}
