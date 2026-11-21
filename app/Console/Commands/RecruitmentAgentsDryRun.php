<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read only: what the Recruitment Agent change (migration 2026_11_18_000001) does to this
 * database, before or after it runs. It writes nothing.
 *
 * The migration renames the agent tables/columns and adds nullable columns: no passport row
 * changes. What changes is the rule: no step moves forward without an approved recruitment
 * agent. This lists, per step, the passports that will be blocked until one is approved.
 */
#[Signature('recruitment-agents:dry-run')]
#[Description('Read-only report: passports per step that need an approved recruitment agent (writes nothing)')]
class RecruitmentAgentsDryRun extends Command
{
    public function handle(): int
    {
        $table = match (true) {
            Schema::hasTable('recruitment_agents') => 'recruitment_agents',
            Schema::hasTable('mygram_agents') => 'mygram_agents',
            default => null,
        };
        $hasAssignments = $table !== null && Schema::hasTable('passport_agent_assignments');

        $this->line('Agent table: '.($table ?? 'none yet (the agent migrations have not run)'));
        $this->line('Migration 2026_11_18_000001 changes passport rows: 0 (only renames and new nullable columns).');
        $this->newLine();

        $passports = DB::table('passport_entries')
            ->whereNull('deleted_at')
            ->whereNull('current_return_id')
            ->select('current_stage')
            ->when($hasAssignments, fn ($q) => $q->selectSub(
                DB::table('passport_agent_assignments')
                    ->selectRaw('count(*)')
                    ->whereColumn('passport_agent_assignments.passport_entry_id', 'passport_entries.id')
                    ->where('status', 'approved')
                    ->whereNull('replaced_by_id'),
                'approved',
            ), fn ($q) => $q->selectRaw('0 as approved'))
            ->get();

        $rows = $passports->groupBy(fn ($p) => $p->current_stage ?? 'passport')
            ->map(fn ($group, $stage) => [
                $stage,
                $group->count(),
                $group->where('approved', '>', 0)->count(),
                $group->where('approved', 0)->count(),
            ])
            ->sortBy(fn ($row) => array_search($row[0], ['passport', 'medical', 'calling', 'visa', 'bmet', 'flight', 'completed'], true))
            ->values();

        $this->table(['Step', 'Passports', 'With approved agent', 'Blocked (no approved agent)'], $rows->all());

        $blocked = $rows->filter(fn ($row) => $row[0] !== 'completed')->sum(fn ($row) => $row[3]);
        $this->newLine();
        $this->warn("{$blocked} passport(s) in progress can't move to their next step until data entry assigns a recruitment agent and the Operations Head approves it.");
        $this->line('Returned passports are not counted. Completed (flight done) passports are not affected.');

        return self::SUCCESS;
    }
}
