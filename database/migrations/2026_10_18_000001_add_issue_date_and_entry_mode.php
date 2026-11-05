<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fit result now records its issue and expiry dates, and a fit passport
     * must be marked Online or Key-in (entry_mode) before it reaches Calling.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('medical_records', 'issue_date')) {
            Schema::table('medical_records', function (Blueprint $table) {
                $table->date('issue_date')->nullable()->after('medical_date');
            });
        }

        if (! Schema::hasColumn('passport_entries', 'entry_mode')) {
            Schema::table('passport_entries', function (Blueprint $table) {
                $table->string('entry_mode', 10)->nullable()->after('medical_date')->index();
            });
        }

        // Existing results: the medical date stands in for the issue date.
        // valid_until is left as it is. Only empty dates are filled.
        DB::table('medical_records')->whereNull('issue_date')->update(['issue_date' => DB::raw('medical_date')]);

        // Recompute every stage. Fit passports without a calling record become
        // medical / fit ("not marked") until someone marks them; passports with
        // a calling record keep their stage, with entry_mode left empty.
        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Reverse the migrations: back to fit passports going straight to Calling.
     */
    public function down(): void
    {
        DB::table('passport_entries')->where('current_stage', 'medical')->where('stage_status', 'fit')
            ->update(['current_stage' => 'calling', 'stage_status' => 'waiting']);

        if (Schema::hasColumn('passport_entries', 'entry_mode')) {
            Schema::table('passport_entries', function (Blueprint $table) {
                $table->dropIndex(['entry_mode']);
                $table->dropColumn('entry_mode');
            });
        }

        if (Schema::hasColumn('medical_records', 'issue_date')) {
            Schema::table('medical_records', function (Blueprint $table) {
                $table->dropColumn('issue_date');
            });
        }
    }
};
