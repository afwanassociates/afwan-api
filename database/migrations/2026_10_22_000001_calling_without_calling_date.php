<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Calling / Work Permit no longer has a calling date: one update saves the
     * receive, issued and expiry dates and makes the step Permitted (the
     * record's date is the receive date). No data changes and nothing is
     * deleted:
     *
     * - older in-process calling records (they only had a calling date) count
     *   as Pending (no completed record); their calling date stays on the
     *   record, and completing them through the new update completes the same
     *   record, keeping that date in details.calling_date;
     * - completed calling records are unchanged.
     *
     * Every count (step lists, tab counts, /workflow/summary) now comes from
     * App\Support\StepTabQuery. The stages are recomputed so every passport's
     * stored stage is current. Safe to run again.
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
