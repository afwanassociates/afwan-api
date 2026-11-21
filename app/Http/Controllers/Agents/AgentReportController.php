<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentAgent;
use App\Support\AgentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The recruitment agent report (passport counts only, no money): the operations head
 * and the accounts head.
 */
class AgentReportController extends Controller
{
    public function index(AgentReport $report): JsonResponse
    {
        Gate::authorize('viewReport', RecruitmentAgent::class);

        return response()->json(['data' => [...$report->rows(), 'buckets' => AgentReport::BUCKETS]]);
    }

    /**
     * An agent's passports ("none" for passports without an agent).
     */
    public function passports(AgentReport $report, string $agent): JsonResponse
    {
        Gate::authorize('viewReport', RecruitmentAgent::class);

        $model = $agent === 'none' ? null : RecruitmentAgent::query()->findOrFail((int) $agent);

        return response()->json([
            'data' => $report->passports($model),
            'agent' => $model ? [
                'id' => $model->id, 'code' => $model->code, 'name' => $model->name, 'rl_number' => $model->rl_number,
            ] : null,
        ]);
    }
}
