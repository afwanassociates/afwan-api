<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flight now runs alongside BMET once the visa is issued: a booking date
     * (in process), then Flight confirmed, which needs BMET cleared and stores
     * the confirmed date. Nothing is deleted. Older flight records (they have
     * a departure date in details):
     *
     * - in process ("ticket booked"): booking_date = the record's date when it
     *   has none, so they stay in Pending with that date;
     * - completed ("departed"): confirmed_date = the record's date, and
     *   booking_date = the record's date when it has none, so they show
     *   under Flight confirmed;
     * - cancelled: kept in the history, no longer counted, so those passports
     *   return to Pending.
     *
     * The stages are recomputed, which also puts every passport with an
     * issued visa and no confirmed flight in Flight Pending as well as BMET
     * Pending. Safe to run again: only missing dates are filled.
     */
    public function up(): void
    {
        $this->eachOlderFlight(function (object $record, array $details): ?array {
            $details['booking_date'] ??= $record->step_date;

            if ($record->status === 'completed') {
                $details['confirmed_date'] ??= $record->step_date;
            }

            return $details;
        });

        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Remove the dates this migration filled in (those equal to the record's
     * own date). Stages are left to the code in place.
     */
    public function down(): void
    {
        $this->eachOlderFlight(function (object $record, array $details): ?array {
            foreach (['booking_date', 'confirmed_date'] as $key) {
                if (($details[$key] ?? null) === $record->step_date) {
                    unset($details[$key]);
                }
            }

            return $details;
        });
    }

    /**
     * Run $change on every older (departure-dated) in-process or completed
     * flight record, soft-deleted ones included, saving its details when
     * they change.
     *
     * @param  Closure(object, array<string, mixed>): ?array<string, mixed>  $change
     */
    protected function eachOlderFlight(Closure $change): void
    {
        DB::table('passport_step_records')
            ->where('step_key', 'flight')
            ->whereIn('status', ['in_process', 'completed'])
            ->whereNotNull('details->departure_date')
            ->orderBy('id')
            ->chunkById(500, function ($records) use ($change) {
                foreach ($records as $record) {
                    $details = json_decode((string) $record->details, true) ?: [];
                    $changed = $change($record, $details);

                    if ($changed !== $details) {
                        DB::table('passport_step_records')->where('id', $record->id)
                            ->update(['details' => $changed === [] ? null : json_encode($changed)]);
                    }
                }
            });
    }
};
