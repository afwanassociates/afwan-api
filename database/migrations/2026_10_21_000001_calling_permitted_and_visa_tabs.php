<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Calling and Visa tabs now read the stored stage (config/workflow.php
     * "tabs"); no data changes and nothing is deleted:
     *
     * - completed calling records with no visa record show under Calling's
     *   Permitted tab (stage visa / waiting);
     * - in-process visa records show under Visa Pending;
     * - issued and rejected visa records keep their tabs.
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
