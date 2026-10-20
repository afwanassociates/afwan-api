<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: money received from a party or an owner, as cash or a loan,
     * in hand or into a bank. Receipt numbers restart every year (CR-2026-0001).
     */
    public function up(): void
    {
        Schema::create('cash_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('receipt_no', 30)->unique();
            $table->date('date');
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('receipt_type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20);
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_branch', 150)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipts');
    }
};
