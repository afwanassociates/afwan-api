<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a party's cash receipt pays for: one line per company with the
     * amount the accountant typed, and the passports (of that party, that
     * company) it pays. A passport may be paid over several receipts (part
     * payments); the line that settles it is a full payment.
     */
    public function up(): void
    {
        Schema::create('cash_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            // Full payment: its passports are paid off. Part payment: more may follow.
            $table->boolean('is_full_payment')->default(true);
            $table->timestamps();

            $table->unique(['cash_receipt_id', 'company_id']);
        });

        Schema::create('cash_receipt_passports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_receipt_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passport_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['cash_receipt_id', 'passport_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_receipt_passports');
        Schema::dropIfExists('cash_receipt_lines');
    }
};
