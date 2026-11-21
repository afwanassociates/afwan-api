<?php

namespace App\Http\Controllers\Agents;

use App\Enums\ApprovalStatus;
use App\Enums\PayeeType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CashPaymentResource;
use App\Http\Resources\RecruitmentAgentResource;
use App\Models\CashPayment;
use App\Models\RecruitmentAgent;
use App\Support\AgentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Agent ledgers (the accounts team): what the company paid each recruitment agent.
 * There is no rate, so no due or balance: only the payments, their total, and the
 * average paid per counted passport.
 */
class AgentPaymentsController extends Controller
{
    /**
     * Every agent with its counted passports and paid totals.
     */
    public function index(AgentReport $report): JsonResponse
    {
        Gate::authorize('viewPayments', RecruitmentAgent::class);

        $counted = $report->countedByAgent();
        $totals = $this->totals();

        // Partner agents only: the Own agent (our company) is never paid.
        $rows = RecruitmentAgent::query()->partners()->orderByDesc('is_active')->orderBy('code')->get()
            ->map(fn (RecruitmentAgent $agent) => [
                ...RecruitmentAgentResource::summary($agent),
                'is_active' => $agent->is_active,
                ...$this->cards($counted[$agent->id] ?? 0, $totals[$agent->id] ?? null),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * One agent's ledger: approved payments with a running total (oldest first).
     */
    public function show(RecruitmentAgent $recruitmentAgent, AgentReport $report): JsonResponse
    {
        Gate::authorize('viewPayments', RecruitmentAgent::class);
        abort_if($recruitmentAgent->is_own, 404);

        $payments = CashPayment::query()
            ->where('payee_type', PayeeType::Agent->value)
            ->where('recruitment_agent_id', $recruitmentAgent->id)
            ->with(['creator:id,name', 'approver:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $running = 0.0;
        $lines = $payments->where('status', ApprovalStatus::Approved)->values()->map(function (CashPayment $payment) use (&$running) {
            $running = round($running + (float) $payment->amount, 2);

            return [...(new CashPaymentResource($payment))->resolve(), 'running_total' => $running];
        });
        $pending = $payments->where('status', ApprovalStatus::Pending)->values()
            ->map(fn (CashPayment $payment) => (new CashPaymentResource($payment))->resolve());

        $counted = $report->countedByAgent($recruitmentAgent->id)[$recruitmentAgent->id] ?? 0;

        return response()->json(['data' => [
            'agent' => [...RecruitmentAgentResource::summary($recruitmentAgent), 'is_active' => $recruitmentAgent->is_active, 'phone' => $recruitmentAgent->phone],
            ...$this->cards($counted, $this->totals($recruitmentAgent->id)[$recruitmentAgent->id] ?? null),
            'payments' => $lines,
            'pending_payments' => $pending,
        ]]);
    }

    /**
     * @return array<int, object{paid: float, pending: float, pending_count: int}>
     */
    private function totals(?int $agentId = null): array
    {
        return CashPayment::query()
            ->where('payee_type', PayeeType::Agent->value)
            ->when($agentId, fn ($q, int $id) => $q->where('recruitment_agent_id', $id))
            ->groupBy('recruitment_agent_id')
            ->selectRaw('recruitment_agent_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'approved' THEN amount END), 0) as paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->toBase()
            ->get()
            ->keyBy('recruitment_agent_id')
            ->all();
    }

    /**
     * @return array{counted_passports: int, total_paid: float, pending_amount: float, pending_count: int, average_per_passport: float|null}
     */
    private function cards(int $counted, ?object $totals): array
    {
        $paid = round((float) ($totals->paid ?? 0), 2);

        return [
            'counted_passports' => $counted,
            'total_paid' => $paid,
            'pending_amount' => round((float) ($totals->pending ?? 0), 2),
            'pending_count' => (int) ($totals->pending_count ?? 0),
            'average_per_passport' => $counted > 0 ? round($paid / $counted, 2) : null,
        ];
    }
}
