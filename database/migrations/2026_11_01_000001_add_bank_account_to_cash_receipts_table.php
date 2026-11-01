<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts into a bank also name the account the money went into: the
     * account name and number. Empty for cash in hand and mobile banking.
     */
    public function up(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->string('bank_account_name', 150)->nullable()->after('bank_branch');
            $table->string('bank_account_number', 30)->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropColumn(['bank_account_name', 'bank_account_number']);
        });
    }
};
