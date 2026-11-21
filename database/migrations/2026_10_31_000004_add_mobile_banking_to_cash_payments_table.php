<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments by mobile banking (bKash, Nagad, Rocket…): the provider and the
     * wallet number the money went to. Empty for cash in hand and bank.
     */
    public function up(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->string('mobile_provider', 30)->nullable()->after('bank_branch');
            $table->string('mobile_number', 20)->nullable()->after('mobile_provider');
        });
    }

    public function down(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropColumn(['mobile_provider', 'mobile_number']);
        });
    }
};
