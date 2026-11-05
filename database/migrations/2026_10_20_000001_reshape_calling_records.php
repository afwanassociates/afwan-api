<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Calling now has two states: a calling date entered (in process) and
     * Permitted (completed, with receive date, issued date and expiry).
     *
     * - Completed calling records get details.receive_date = the record's
     *   date when it has none; the issued date stays empty and valid_until is
     *   unchanged (shown as "-" where missing).
     * - In-process records stay as they are (Pending, with their calling date).
     * - Rejected calling records stay in the history but no longer count (the
     *   step has no rejected status), so those passports go back to Pending
     *   without a calling date once their stage is recomputed.
     *
     * Safe to run again: only missing receive dates are filled.
     */
    public function up(): void
    {
        DB::table('passport_step_records')
            ->where('step_key', 'calling')
            ->where('status', 'completed')
            ->orderBy('id')
            ->chunkById(500, function ($records) {
                foreach ($records as $record) {
                    $details = json_decode((string) $record->details, true) ?: [];

                    if (! empty($details['receive_date'])) {
                        continue;
                    }

                    $details['receive_date'] = $record->step_date;

                    DB::table('passport_step_records')->where('id', $record->id)->update(['details' => json_encode($details)]);
                }
            });

        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Remove the receive dates this migration filled in (completed calling
     * records whose receive date is their own date and that have no issued
     * date). Stages are left to the code in place.
     */
    public function down(): void
    {
        DB::table('passport_step_records')
            ->where('step_key', 'calling')
            ->where('status', 'completed')
            ->orderBy('id')
            ->chunkById(500, function ($records) {
                foreach ($records as $record) {
                    $details = json_decode((string) $record->details, true) ?: [];

                    if (($details['receive_date'] ?? null) !== $record->step_date || ! empty($details['issue_date'])) {
                        continue;
                    }

                    unset($details['receive_date']);

                    DB::table('passport_step_records')->where('id', $record->id)
                        ->update(['details' => $details === [] ? null : json_encode($details)]);
                }
            });
    }
};
