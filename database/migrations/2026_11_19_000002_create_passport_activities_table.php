<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what on a passport, entry to flight: the actions that leave no record
 * of their own (the Online mark, slip edits, step edits and undos, agent
 * approvals, …). Shown in the passport history. Rows are only ever added.
 *
 * Additive only: a new table, no existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_entry_id')->constrained('passport_entries')->cascadeOnDelete();
            $table->string('step_key', 32);
            $table->string('action', 64);
            $table->string('title');
            $table->json('details')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['passport_entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_activities');
    }
};
