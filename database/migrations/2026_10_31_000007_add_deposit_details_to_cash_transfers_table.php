<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deposits to a bank: the deposit slip number, the transaction type
     * (NPSB / BFTN) and the reference person (name, mobile, NID or passport).
     * Empty for withdrawals and for deposits saved before this.
     */
    public function up(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->string('deposit_slip_no', 50)->nullable()->after('bank_branch');
            $table->string('transaction_type', 10)->nullable()->after('deposit_slip_no');
            $table->string('reference_name', 150)->nullable()->after('transaction_type');
            $table->string('reference_mobile', 30)->nullable()->after('reference_name');
            $table->string('reference_nid_passport', 50)->nullable()->after('reference_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropColumn(['deposit_slip_no', 'transaction_type', 'reference_name', 'reference_mobile', 'reference_nid_passport']);
        });
    }
};
