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
        // All optional; existing companies keep null values.
        Schema::table('companies', function (Blueprint $table) {
            $table->string('agent_name', 150)->nullable()->after('country_code');
            $table->string('agent_phone', 30)->nullable()->after('agent_name');
            $table->string('agent_email', 150)->nullable()->after('agent_phone');
            $table->string('bd_agency_name', 150)->nullable()->after('agent_email');
            // How many workers the company asked for; the report shows the balance.
            $table->unsignedInteger('quota')->nullable()->after('bd_agency_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['agent_name', 'agent_phone', 'agent_email', 'bd_agency_name', 'quota']);
        });
    }
};
