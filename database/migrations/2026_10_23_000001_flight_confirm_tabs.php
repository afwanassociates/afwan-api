<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Flight now has Waiting for BMET, Pending and Flight confirmed (final).
     * No data changes and nothing is deleted:
     *
     * - in-process ("ticket booked") flight records stay in Pending with
     *   their date; confirming completes the same record and keeps that date
     *   in details.booking_date;
     * - completed ("departed") records show under Flight confirmed;
     * - cancelled flight records stay in the history but no longer count
     *   (the step has no cancelled status), so those passports return to
     *   Pending, or Waiting for BMET if their BMET is not completed.
     *
     * The stages are recomputed so every passport's stored stage is current.
     * Safe to run again.
     */
    public function up(): void
    {
        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Nothing to undo: no data was changed.
     */
    public function down(): void
    {
        //
    }
};
