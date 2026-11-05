<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cash payment to a party or owner owns its ledger line, like a receipt.
     */
    public function up(): void
    {
        Schema::table('party_transactions', function (Blueprint $table) {
            $table->foreignId('cash_payment_id')->nullable()->unique()->after('cash_receipt_id')
                ->constrained('cash_payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_payment_id');
        });
    }
};
