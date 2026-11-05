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
        // A passport's reference is optional. Existing rows keep theirs.
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('reference_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. Refuses while passports without a reference exist.
     */
    public function down(): void
    {
        if (DB::table('passport_entries')->whereNull('reference_id')->exists()) {
            throw new RuntimeException('Some passports have no reference. Give them one before rolling this migration back.');
        }

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('reference_id')->nullable(false)->change();
        });
    }
};
