<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An owner loan repayment names the loan (the cash receipt of type loan)
     * it pays back, so each loan shows what is repaid and still owed.
     */
    public function up(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreignId('loan_receipt_id')->nullable()->after('payment_type')
                ->constrained('cash_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_receipt_id');
        });
    }
};
