<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: the company's cash-in-hand opening balance and its date (one
     * row). Every cash write locks this row, so balance checks never race.
     */
    public function up(): void
    {
        Schema::create('cash_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('cash_settings')->insert(['id' => 1, 'opening_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_settings');
    }
};
