<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Where each passport is in the workflow, kept up to date only by
        // App\Services\WorkflowService::recompute. current_stage is medical,
        // calling, visa, bmet, flight or completed; stage_status is pending,
        // unfit, waiting, in_process or rejected (null once completed).
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->string('current_stage', 20)->default('medical')->after('current_medical_id')->index();
            $table->string('stage_status', 20)->nullable()->after('current_stage')->index();
        });

        // Backfill existing rows (stage, status and stage_status_date), in chunks.
        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropIndex(['current_stage']);
            $table->dropIndex(['stage_status']);
            $table->dropColumn(['current_stage', 'stage_status']);
        });
    }
};
