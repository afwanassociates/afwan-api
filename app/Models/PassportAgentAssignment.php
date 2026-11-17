<?php

namespace App\Models;

use App\Enums\AgentAssignmentStatus;
use App\Enums\AgentCountDecision;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assignment of a passport to a MYGRAM agent. Rows are never deleted: a
 * returned one, a replaced one and the current one all stay in the history.
 * Every write goes through App\Services\AgentAssignmentService.
 *
 * The current agent is the approved row not replaced by a later one.
 */
class PassportAgentAssignment extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'ended_date' => 'date',
            'status' => AgentAssignmentStatus::class,
            'count_decision' => AgentCountDecision::class,
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->status === AgentAssignmentStatus::Approved && $this->replaced_by_id === null;
    }

    /**
     * Approved rows not replaced: each passport's current agent.
     *
     * @param  Builder<PassportAgentAssignment>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('status', AgentAssignmentStatus::Approved->value)->whereNull('replaced_by_id');
    }

    /**
     * Rows that count for their agent, before the passport check (returned or
     * unfit passports never count): the current one, or a replaced one the
     * accounts head said Yes to.
     *
     * @param  Builder<PassportAgentAssignment>  $query
     */
    #[Scope]
    protected function countable(Builder $query): void
    {
        $query->where('status', AgentAssignmentStatus::Approved->value)
            ->where(fn (Builder $q) => $q->whereNull('replaced_by_id')
                ->orWhere('count_decision', AgentCountDecision::Yes->value));
    }

    /**
     * @return BelongsTo<PassportEntry, $this>
     */
    public function passportEntry(): BelongsTo
    {
        return $this->belongsTo(PassportEntry::class);
    }

    /**
     * @return BelongsTo<MygramAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(MygramAgent::class, 'mygram_agent_id');
    }

    /**
     * @return BelongsTo<PassportAgentAssignment, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
