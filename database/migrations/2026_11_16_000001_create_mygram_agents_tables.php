<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYGRAM agents: the partner agents (each with an RL number) passports go to for
 * the MYGRAM / medical slip. Data entry assigns a passport to an agent, the
 * operations head approves it or returns it for correction, and the history of
 * every assignment is kept. The company pays agents in part payments (cash
 * payments with payee_type "agent"); agents never pay the company.
 *
 * Only new tables and one nullable column on cash_payments: no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mygram_agents', function (Blueprint $table) {
            $table->id();
            // AG-001, AG-002, … given once, never edited.
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            // Stored upper-cased; unique.
            $table->string('rl_number', 50)->unique();
            $table->string('phone', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('passport_agent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_entry_id')->constrained('passport_entries')->cascadeOnDelete();
            $table->foreignId('mygram_agent_id')->constrained('mygram_agents')->restrictOnDelete();
            // The date the passport went to the agent (never in the future).
            $table->date('assigned_date');
            // The remark of a first assignment, or the reason of a change.
            $table->string('remark', 500)->nullable();
            // pending (waiting for the operations head), approved, returned (for correction).
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            // Set when a later approved change replaced this assignment ("To" date = that one's date).
            $table->foreignId('replaced_by_id')->nullable()->constrained('passport_agent_assignments')->nullOnDelete();
            $table->date('ended_date')->nullable();
            // After a change: does the passport still count for this (old) agent?
            // waiting (the accounts head has not decided), yes, no. Null while current.
            $table->string('count_decision', 20)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['passport_entry_id', 'status']);
            $table->index(['mygram_agent_id', 'status']);
        });

        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreignId('mygram_agent_id')->nullable()->after('party_id')
                ->constrained('mygram_agents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mygram_agent_id');
        });
        Schema::dropIfExists('passport_agent_assignments');
        Schema::dropIfExists('mygram_agents');
    }
};
