<?php

namespace Database\Seeders;

use App\Enums\StepStatus;
use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class WorkflowDataSeeder extends Seeder
{
    /**
     * Development sample data: calling, visa, BMET and flight records for the
     * sample passports with a valid fit medical, covering every stage and
     * status (waiting, in process, rejected then recorded again, completed,
     * flights booked and departed, expiring and expired visas). Passports with
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
            'calling_applied', 'calling_rejected_then_applied', 'calling_approved',
            'visa_submitted', 'visa_rejected', 'visa_issued_expiring',
            'bmet_submitted', 'bmet_cleared_visa_expired', 'flight_booked', 'flight_departed',
        ];

        foreach ($passports as $index => $passport) {
            $scenario = $scenarios[$index % count($scenarios)];

            // One day apart after the medical, never after today.
            $base = LocalDate::parse($passport->currentMedical->medical_date);
            $day = fn (int $days): string => $base->addDays($days)->min($today)->toDateString();

            $record = fn (string $step, StepStatus $status, int $days, array $extra = []) => $workflow->recordStep($passport, $step, [
                'status' => $status,
                'step_date' => $day($days),
                'reference_no' => $status === StepStatus::Completed && $step !== 'flight' ? strtoupper($step).'-'.(1000 + $passport->id) : null,
                ...$extra,
            ], $clerk);

            $flight = fn (CarbonImmutable $departure) => [
                'details' => ['airline' => 'Sample Air', 'flight_no' => 'SA'.(100 + $passport->id), 'departure_date' => $departure->toDateString()],
            ];

            match ($scenario) {
                'calling_applied' => $record('calling', StepStatus::InProcess, 1),
                'calling_rejected_then_applied' => [
                    $record('calling', StepStatus::Rejected, 1, ['remarks' => 'Sample remark: documents incomplete.']),
                    $record('calling', StepStatus::InProcess, 2),
                ],
                'calling_approved' => $record('calling', StepStatus::Completed, 1, ['valid_until' => $today->addMonths(6)->toDateString()]),
                default => null,
            };

            if (in_array($scenario, ['visa_submitted', 'visa_rejected', 'visa_issued_expiring', 'bmet_submitted', 'bmet_cleared_visa_expired', 'flight_booked', 'flight_departed'], true)) {
                $record('calling', StepStatus::Completed, 1, ['valid_until' => $today->addMonths(6)->toDateString()]);
            }

            match ($scenario) {
                'visa_submitted' => $record('visa', StepStatus::InProcess, 2),
                'visa_rejected' => $record('visa', StepStatus::Rejected, 2, ['remarks' => 'Sample remark: visa refused.']),
                // Within the 30-day warning period.
                'visa_issued_expiring' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->addDays(20)->toDateString()]),
                'bmet_cleared_visa_expired' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->subDay()->toDateString()]),
                'bmet_submitted', 'flight_booked', 'flight_departed' => $record('visa', StepStatus::Completed, 2, ['valid_until' => $today->addMonths(3)->toDateString()]),
                default => null,
            };

            match ($scenario) {
                'bmet_submitted' => $record('bmet', StepStatus::InProcess, 3),
                'bmet_cleared_visa_expired', 'flight_booked', 'flight_departed' => $record('bmet', StepStatus::Completed, 3),
                default => null,
            };

            match ($scenario) {
                'flight_booked' => $record('flight', StepStatus::InProcess, 4, $flight($today->addDays(14))),
                'flight_departed' => $record('flight', StepStatus::Completed, 4, $flight($today)),
                default => null,
            };
        }
    }
}
