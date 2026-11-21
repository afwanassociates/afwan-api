<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MYGRAM agents become Recruitment Agents, with one "Own" agent (our company,
 * is_own) that goes through the same steps. Partner agents' passports come
 * back to the office after BMET clearance ("Received at Office") before the
 * flight.
 *
 * Additive and renames only: no existing row is changed or deleted, so it is
 * safe on a database that already has passports (production) and on one that
 * already has agent data (local/dev). Nothing is seeded: the operations head
 * adds the Own agent with the company's RL number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['mygram_agent_id']);
            $table->dropIndex(['mygram_agent_id', 'status']);
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropForeign(['mygram_agent_id']);
        });

        Schema::rename('mygram_agents', 'recruitment_agents');

        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->renameColumn('mygram_agent_id', 'recruitment_agent_id');
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->renameColumn('mygram_agent_id', 'recruitment_agent_id');
        });

        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->foreign('recruitment_agent_id')->references('id')->on('recruitment_agents')->restrictOnDelete();
            $table->index(['recruitment_agent_id', 'status']);
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreign('recruitment_agent_id')->references('id')->on('recruitment_agents')->restrictOnDelete();
        });

        Schema::table('recruitment_agents', function (Blueprint $table) {
            // Our own company as an agent: at most one, never paid, never deactivated.
            $table->boolean('is_own')->default(false)->after('rl_number');
        });

        Schema::table('passport_entries', function (Blueprint $table) {
            // A partner agent's passport back at our office after BMET clearance.
            $table->date('office_received_date')->nullable();
            $table->foreignId('office_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('office_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_received_by');
            $table->dropColumn(['office_received_date', 'office_received_at']);
        });

        Schema::table('recruitment_agents', function (Blueprint $table) {
            $table->dropColumn('is_own');
        });

        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['recruitment_agent_id']);
            $table->dropIndex(['recruitment_agent_id', 'status']);
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->dropForeign(['recruitment_agent_id']);
        });

        Schema::rename('recruitment_agents', 'mygram_agents');

        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->renameColumn('recruitment_agent_id', 'mygram_agent_id');
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->renameColumn('recruitment_agent_id', 'mygram_agent_id');
        });

        Schema::table('passport_agent_assignments', function (Blueprint $table) {
            $table->foreign('mygram_agent_id')->references('id')->on('mygram_agents')->restrictOnDelete();
            $table->index(['mygram_agent_id', 'status']);
        });
        Schema::table('cash_payments', function (Blueprint $table) {
            $table->foreign('mygram_agent_id')->references('id')->on('mygram_agents')->restrictOnDelete();
        });
    }
};
