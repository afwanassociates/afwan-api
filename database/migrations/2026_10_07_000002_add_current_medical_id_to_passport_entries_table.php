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
        // The latest non-deleted medical record, kept up to date by
        // App\Services\MedicalService. Null means the medical is pending.
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->foreignId('current_medical_id')->nullable()->after('passport_expiry_date')
                ->constrained('medical_records')->nullOnDelete();

            // The medical queue: pending passports, oldest received first.
            $table->index(['current_medical_id', 'passport_received_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropForeign(['current_medical_id']);
            $table->dropIndex(['current_medical_id', 'passport_received_date']);
            $table->dropColumn('current_medical_id');
        });
    }
};
