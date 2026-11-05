<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner loans go the other way: the company lends an owner money (a cash
     * payment of type owner_loan), and the owner settles it by returning money
     * (a cash receipt of type loan_return that names the loan) or by showing
     * company bills paid with it (an owner loan bill, with a copy of the bill).
     * Money an owner puts into the company is capital, never a loan.
     */
    public function up(): void
    {
        // Loan repayments to owners are gone.
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_receipt_id');
        });

        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->foreignId('owner_loan_id')->nullable()->after('receipt_type')
                ->constrained('cash_payments')->restrictOnDelete();
        });

        Schema::create('owner_loan_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_loan_id')->constrained('cash_payments')->restrictOnDelete();
            $table->date('date');
            $table->string('expense_category', 30);
            $table->string('purpose', 150);
            $table->string('bill_no', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('note', 500)->nullable();
            // The bill copy on the private disk (owner-loan-bills/{id}/{uuid}.{ext}).
            $table->string('copy_path')->nullable();
            $table->string('copy_original_name')->nullable();
            $table->string('copy_mime', 100)->nullable();
            $table->unsignedInteger('copy_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Approval, as on the other accounts entries.
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('party_transactions', function (Blueprint $table) {
            $table->foreignId('owner_loan_bill_id')->nullable()->unique()->after('cash_payment_id')
                ->constrained('owner_loan_bills')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_loan_bill_id');
        });
        Schema::dropIfExists('owner_loan_bills');
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_loan_id');
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreignId('loan_receipt_id')->nullable()->after('payment_type')
                ->constrained('cash_receipts')->nullOnDelete();
        });
    }
};
