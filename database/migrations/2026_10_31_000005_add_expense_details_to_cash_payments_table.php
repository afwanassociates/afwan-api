<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses (payments to an "other" payee): what the money was spent on
     * (office rent, utility bill, tea…) and the bill / receipt / voucher number
     * from the payee. Required category is enforced by the form; nullable for
     * party and owner payments and for expenses saved before this.
     */
    public function up(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->string('expense_category', 30)->nullable()->after('payment_type');
            $table->string('bill_no', 50)->nullable()->after('purpose');
            $table->index('expense_category');
        });
    }

    public function down(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropIndex(['expense_category']);
            $table->dropColumn(['expense_category', 'bill_no']);
        });
    }
};
