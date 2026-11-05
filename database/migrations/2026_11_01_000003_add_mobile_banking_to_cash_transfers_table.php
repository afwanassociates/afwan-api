<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deposit / withdrawal moves cash to or from a bank or a mobile banking
     * wallet (bKash, Nagad…: cash in / cash out). Existing ones are bank
     * transfers. A mobile banking one has the service and wallet number
     * instead of the bank name and branch.
     */
    public function up(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->string('channel', 20)->default('bank')->after('direction');
            $table->string('bank_name', 150)->nullable()->change();
            $table->string('bank_branch', 150)->nullable()->change();
            $table->string('mobile_provider', 30)->nullable()->after('bank_branch');
            $table->string('mobile_number', 20)->nullable()->after('mobile_provider');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropColumn(['channel', 'mobile_provider', 'mobile_number']);
        });
    }
};
