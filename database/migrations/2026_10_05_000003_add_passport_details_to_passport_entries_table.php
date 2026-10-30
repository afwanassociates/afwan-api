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
        // All nullable: entries made before these fields existed have no values.
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('passport_number')->index();
            $table->date('date_of_birth')->nullable()->after('country_code');
            $table->date('passport_expiry_date')->nullable()->after('passport_received_date');
            $table->foreignId('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();

            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
            $table->dropIndex(['country_code']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['country_code', 'date_of_birth', 'passport_expiry_date']);
        });
    }
};
