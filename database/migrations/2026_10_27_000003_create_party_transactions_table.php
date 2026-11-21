<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: the lines of each party ledger. A cash receipt owns its line
     * (deleted with it); receivable and refund lines are entered by hand.
     */
    public function up(): void
    {
        Schema::create('party_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('cash_receipt_id')->nullable()->unique()->constrained('cash_receipts')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['party_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_transactions');
    }
};
