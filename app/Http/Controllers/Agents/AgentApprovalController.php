<?php

namespace App\Http\Controllers\Agents;

use App\Enums\AgentAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentAssignmentResource;
use App\Models\PassportAgentAssignment;
use App\Services\AgentAssignmentService;
use App\Support\Like;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agent Approvals (the operations head): approve a recruitment agent assignment or
 * return it to data entry for correction.
 */
class AgentApprovalController extends Controller
{
    private const RELATIONS = ['agent', 'assigner', 'approver', 'returner', 'passportEntry.currentAgentAssignment.agent'];

    public function __construct(private readonly AgentAssignmentService $service) {}

    /**
     * Assignments of one status (pending by default), oldest first for pending.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $request->validate([
            'status' => ['sometimes', Rule::in(AgentAssignmentStatus::values())],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $status = $request->input('status', AgentAssignmentStatus::Pending->value);
        $search = trim((string) $request->input('q'));

        $rows = PassportAgentAssignment::query()
            ->where('status', $status)
            ->whereHas('passportEntry')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereHas('passportEntry', fn (Builder $p) => $p
                    ->where('passport_name', 'like', Like::contains($search))
                    ->orWhere('passport_number', 'like', Like::contains((string) Normalize::passportNumber($search))))
                ->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', Like::contains($search)))))
            ->with(self::RELATIONS)
            ->orderBy('id', $status === AgentAssignmentStatus::Pending->value ? 'asc' : 'desc')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return AgentAssignmentResource::collection($rows);
    }

    /**
     * The pending count for the sidebar.
     */
    public function summary(): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        return response()->json(['data' => [
            'pending' => PassportAgentAssignment::query()
                ->where('status', AgentAssignmentStatus::Pending->value)
                ->whereHas('passportEntry')
                ->count(),
        ]]);
    }

    public function approve(Request $request, PassportAgentAssignment $assignment): AgentAssignmentResource
    {
        Gate::authorize('approve', $assignment);

        return new AgentAssignmentResource($this->service->approve($assignment, $request->user())->load(self::RELATIONS));
    }

    public function sendBack(Request $request, PassportAgentAssignment $assignment): AgentAssignmentResource
    {
        Gate::authorize('approve', $assignment);

        $reason = Normalize::name($request->string('reason')->toString());
        if ($reason === null || $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter why the assignment needs correction.']);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason may not be longer than 500 characters.']);
        }

        return new AgentAssignmentResource($this->service->sendBack($assignment, $request->user(), $reason)->load(self::RELATIONS));
    }

    /**
     * Approve many at once; the ones that cannot be are skipped with the reason.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];
        $rows = PassportAgentAssignment::query()->whereIn('id', $ids)->with('passportEntry')->get()->keyBy('id');
        $user = $request->user();
        $describe = fn (int $id) => [
            'id' => $id,
            'passport_number' => $rows->get($id)?->passportEntry?->passport_number,
            'passport_name' => $rows->get($id)?->passportEntry?->passport_name,
        ];

        $result = $this->service->bulk($rows->keys()->all(), function (int $id) use ($rows, $user, $describe) {
            $this->service->approve($rows->get($id), $user);

            return $describe($id);
        }, $describe);

        return response()->json(['data' => $result]);
    }
}
