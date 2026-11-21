<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company now has only a name, country and quota: the agent and
     * Bangladesh agency fields are dropped (their values are lost).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['agent_name', 'agent_phone', 'agent_email', 'bd_agency_name']);
        });
    }

    /**
     * Reverse the migrations (the columns come back empty).
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('agent_name', 150)->nullable()->after('country_code');
            $table->string('agent_phone', 30)->nullable()->after('agent_name');
            $table->string('agent_email', 150)->nullable()->after('agent_phone');
            $table->string('bd_agency_name', 150)->nullable()->after('agent_email');
        });
    }
};
