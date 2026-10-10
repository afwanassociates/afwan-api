<?php

namespace Database\Seeders;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\MedicalService;
use App\Support\LocalDate;
use Illuminate\Database\Seeder;

class MedicalDataSeeder extends Seeder
{
    /**
     * Development sample data: medical records for the sample passports,
     * covering every status (not started, pending, fit, expiring soon,
     * expired, unfit) and an unfit passport that passed a re-test. Each
     * passport with a result (or pending) gets its medical slip first.
     */
    public function run(MedicalService $medicals): void
    {
        if (app()->isProduction()) {
            $this->command?->error('MedicalDataSeeder creates sample medical data and will not run in production.');

            return;
        }

        // Only add records once, so running the seeder again does not pile them up.
        if (MedicalRecord::withTrashed()->exists()) {
            $this->command?->info('Medical records already exist; skipped.');

            return;
        }

        $clerk = User::query()->where('role', UserRole::DataEntry)->first();
        $admin = User::query()->where('role', UserRole::Admin)->first() ?? $clerk;

        if ($clerk === null) {
            $this->command?->warn('No data entry user found; run StaffUserSeeder first.');

            return;
        }

        $today = LocalDate::today();
        $months = (int) config('medical.validity_months');
        $soonDays = (int) config('medical.expiring_soon_days');

        // Oldest passports first, so the scenarios needing older medicals tend
        // to land on passports that were received earlier.
        $passports = PassportEntry::query()->orderBy('passport_received_date')->orderBy('id')->get();

        $scenarios = ['expired', 'retested', 'expiring_soon', 'fit', 'unfit', 'pending'];
        $centers = MedicalCenter::query()->active()->orderBy('id')->pluck('id');

        foreach ($passports as $index => $passport) {
            // A recent medical, but never before the passport was received.
            $recent = fn (int $daysAgo): string => $today->subDays($daysAgo)
                ->max(LocalDate::parse($passport->passport_received_date))
                ->min($today)
                ->toDateString();

            $records = match ($scenarios[$index % count($scenarios)]) {
                'pending' => [],
                'fit' => [
                    [$clerk, ['medical_date' => $recent(20), 'result' => MedicalResult::Fit]],
                ],
                'expiring_soon' => [
                    // Leaves about half the warning period.
                    [$clerk, ['medical_date' => $today->addDays(intdiv($soonDays, 2))->subMonthsNoOverflow($months)->toDateString(), 'result' => MedicalResult::Fit]],
                ],
                'expired' => [
                    [$clerk, ['medical_date' => $today->subMonthsNoOverflow($months + 1)->toDateString(), 'result' => MedicalResult::Fit]],
                ],
                'unfit' => [
                    [$clerk, ['medical_date' => $recent(10), 'result' => MedicalResult::Unfit, 'remarks' => 'Sample remark: failed the medical examination.']],
                ],
                // Only admin and super_admin may re-test an unfit passport.
                'retested' => [
                    [$clerk, ['medical_date' => $today->subMonths(2)->toDateString(), 'result' => MedicalResult::Unfit, 'remarks' => 'Sample remark: re-test advised.']],
                    [$admin, ['medical_date' => $today->subDays(5)->toDateString(), 'result' => MedicalResult::Fit, 'remarks' => 'Sample remark: passed the re-test.']],
                ],
            };

            // Every "pending" passport but one in two gets its slip date (the
            // others stay not_started); a result always needs the slip first.
            $isPending = $scenarios[$index % count($scenarios)] === 'pending';
            $hasSlip = $records !== [] || ($isPending && intdiv($index, count($scenarios)) % 2 === 0);

            if ($hasSlip) {
                $firstMedical = $records[0][1]['medical_date'] ?? $recent(3);

                $medicals->updateSlip($passport, [
                    'medical_slip_date' => LocalDate::parse($firstMedical)->subDays(3)->toDateString(),
                    'medical_slip_no' => 'MG-'.(5000 + $passport->id),
                    'medical_center_id' => $centers->isEmpty() ? null : $centers[$index % $centers->count()],
                ]);
            }

            foreach ($records as [$actor, $data]) {
                $medicals->record($passport, $data, $actor);
            }
        }
    }
}
