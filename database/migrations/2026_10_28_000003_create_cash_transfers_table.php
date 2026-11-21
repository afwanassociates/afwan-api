<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: cash in hand deposited to a bank, or withdrawn from a bank
     * into cash (CT-2026-0001). The bank is typed until Bank Management exists.
     */
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('transfer_no', 30)->unique();
            $table->date('date');
            $table->string('direction', 20);
            $table->decimal('amount', 15, 2);
            $table->string('bank_name', 150);
            $table->string('bank_branch', 150);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
