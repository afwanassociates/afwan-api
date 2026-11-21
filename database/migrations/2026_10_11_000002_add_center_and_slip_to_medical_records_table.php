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
        // All nullable: medicals recorded before these fields existed have no values.
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('medical_center_id')->nullable()->after('passport_entry_id')
                ->constrained('medical_centers')->restrictOnDelete();
            // The medical slip (the client calls it MYGRAM). No ordering rule
            // against medical_date.
            $table->string('slip_no', 50)->nullable()->after('remarks');
            $table->date('slip_date')->nullable()->after('slip_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_center_id');
            $table->dropColumn(['slip_no', 'slip_date']);
        });
    }
};
