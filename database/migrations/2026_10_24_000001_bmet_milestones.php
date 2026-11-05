<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * BMET Clearance is now Training done, Finger done, then BMET clearance
     * done, with only the Pending and BMET cleared tabs. No data changes and
     * nothing is deleted:
     *
     * - in-process ("submitted") BMET records stay in Pending; their training
     *   and finger dates are already in details.training_certificate_date and
     *   details.finger_date, which the milestones use;
     * - completed records show under BMET cleared;
     * - rejected BMET records stay in the history but no longer count (the
     *   step has no rejected status), so those passports return to Pending.
     *
     * Visa records need no change. The stages are recomputed so every
     * passport's stored stage is current. Safe to run again.
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
