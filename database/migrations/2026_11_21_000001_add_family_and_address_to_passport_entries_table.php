<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The passport's personal data page: father's name, mother's name and permanent
 * address. Filled from the scan copy on the add passport form (or typed).
 *
 * Additive only: nullable columns, no existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->string('father_name', 150)->nullable()->after('passport_expiry_date');
            $table->string('mother_name', 150)->nullable()->after('father_name');
            $table->string('permanent_address', 255)->nullable()->after('mother_name');
        });
    }

    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropColumn(['father_name', 'mother_name', 'permanent_address']);
        });
    }
};
