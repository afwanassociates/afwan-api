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
        // The passport's scan copy, kept on the private disk by
        // App\Services\PassportScanService. All nullable: existing passports
        // have no scan (has_scan = false). scan_path is never sent to clients.
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->string('scan_path')->nullable()->after('medical_date');
            $table->string('scan_original_name')->nullable()->after('scan_path');
            $table->string('scan_mime', 100)->nullable()->after('scan_original_name');
            $table->unsignedInteger('scan_size')->nullable()->after('scan_mime');
            $table->timestamp('scan_uploaded_at')->nullable()->after('scan_size');
        });
    }

    /**
     * Reverse the migrations. Stored files stay on the private disk.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropColumn(['scan_path', 'scan_original_name', 'scan_mime', 'scan_size', 'scan_uploaded_at']);
        });
    }
};
