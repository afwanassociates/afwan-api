<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Withdrawals from a bank: the cheque number. Empty for deposits and for
     * withdrawals saved before this.
     */
    public function up(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->string('cheque_no', 50)->nullable()->after('bank_branch');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transfers', function (Blueprint $table) {
            $table->dropColumn('cheque_no');
        });
    }
};
