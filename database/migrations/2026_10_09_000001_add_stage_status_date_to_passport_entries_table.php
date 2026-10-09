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
        // The date of the event behind the passport's latest status, set only by
        // App\Services\WorkflowService::recompute (see "All Passports" overview).
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->date('stage_status_date')->nullable()->after('current_medical_id')->index();
        });

        // Backfill existing rows, in chunks.
        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropIndex(['stage_status_date']);
            $table->dropColumn('stage_status_date');
        });
    }
};
