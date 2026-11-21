<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operations head can undo an agent approval (the assignment goes back to
 * "waiting for approval"); who did it and when is kept on the row.
 *
 * Additive only: two nullable columns, no existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->foreignId('approval_undone_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_undone_at')->nullable()->after('approval_undone_by');
        });
    }

    public function down(): void
    {
        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_undone_by');
            $table->dropColumn('approval_undone_at');
        });
    }
};
