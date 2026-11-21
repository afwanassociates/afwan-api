<?php

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
        // The medical slip keeps its date and medical centre; the slip number
        // is no longer recorded.
        if (Schema::hasColumn('passport_entries', 'medical_slip_no')) {
            Schema::table('passport_entries', function (Blueprint $table) {
                $table->dropColumn('medical_slip_no');
            });
        }
    }

    /**
     * Reverse the migrations. The column comes back empty: dropped values are gone.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('passport_entries', 'medical_slip_no')) {
            Schema::table('passport_entries', function (Blueprint $table) {
                $table->string('medical_slip_no', 50)->nullable()->after('medical_slip_date');
            });
        }
    }
};
