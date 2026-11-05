<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The date the passport went for its medical, entered before the result
        // (PATCH medical-date); the result then uses it.
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->date('medical_date')->nullable()->after('medical_center_id');
        });

        // Passports that already have a result take its date. Only empty dates
        // are filled, so running it again changes nothing. Pending passports
        // with a slip but no result keep an empty medical date.
        DB::table('passport_entries')
            ->whereNull('medical_date')
            ->whereNotNull('current_medical_id')
            ->update(['medical_date' => DB::raw('(select medical_records.medical_date from medical_records where medical_records.id = passport_entries.current_medical_id)')]);
    }

    /**
     * Reverse the migrations. The results keep their own dates.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropColumn('medical_date');
        });
    }
};
