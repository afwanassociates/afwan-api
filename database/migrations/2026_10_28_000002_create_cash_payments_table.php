<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: money paid out to a party, an owner or any other payee, in
     * cash or from a bank. Payment numbers restart every year (CP-2026-0001).
     */
    public function up(): void
    {
        Schema::create('cash_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('payment_no', 30)->unique();
            $table->date('date');
            $table->string('payee_type', 20);
            $table->foreignId('party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->string('payee_name', 150)->nullable();
            $table->string('payment_type', 20);
            $table->string('purpose', 150)->nullable();
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
        Schema::dropIfExists('cash_payments');
    }
};
