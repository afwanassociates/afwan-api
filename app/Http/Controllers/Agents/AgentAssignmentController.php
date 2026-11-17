<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agents\AssignAgentRequest;
use App\Http\Requests\Agents\BulkAssignAgentRequest;
use App\Http\Resources\AgentAssignmentResource;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Services\AgentAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Data entry assigns or changes a passport's MYGRAM agent (sent to the
 * operations head for approval); the passport team reads the history.
 */
class AgentAssignmentController extends Controller
{
    public function __construct(private readonly AgentAssignmentService $service) {}

    /**
     * Every assignment of the passport, oldest first (returned ones included).
     */
    public function index(PassportEntry $passport): AnonymousResourceCollection
    {
        Gate::authorize('view', $passport);

        $rows = $passport->agentAssignments()->with(['agent', 'assigner', 'approver', 'returner', 'decider'])->get()
            // "counted" needs the passport: it is the same one for every row.
            ->each(fn (PassportAgentAssignment $row) => $row->setRelation('passportEntry', $passport));

        return AgentAssignmentResource::collection($rows);
    }

    /**
     * Assign the agent, or change it (the reason is required then).
     */
    public function store(AssignAgentRequest $request, PassportEntry $passport): AgentAssignmentResource
    {
        Gate::authorize('assign', [PassportAgentAssignment::class, $passport]);

        $assignment = $this->service->assign($passport, $request->validated(), $request->user());

        return new AgentAssignmentResource($assignment->load(['agent', 'assigner']));
    }

    /**
     * One agent and date for many passports. Only passports without an agent;
     * the others are skipped with the reason.
     */
    public function bulk(BulkAssignAgentRequest $request): JsonResponse
    {
        $data = $request->safe()->except('passport_ids');
        $passports = PassportEntry::query()->whereIn('id', $request->validated('passport_ids'))->get()->keyBy('id');
        $user = $request->user();
        $describe = fn (int $id) => [
            'id' => $id,
            'passport_number' => $passports->get($id)?->passport_number,
            'passport_name' => $passports->get($id)?->passport_name,
        ];

        $result = $this->service->bulk(
            $passports->keys()->all(),
            function (int $id) use ($passports, $data, $user, $describe) {
                $passport = $passports->get($id);
                if (! $user->can('assign', [PassportAgentAssignment::class, $passport])) {
                    throw ValidationException::withMessages(['passport' => 'You are not allowed to assign an agent to this passport.']);
                }
                $this->service->assign($passport, $data, $user, firstOnly: true);

                return $describe($id);
            },
            $describe,
        );

        return response()->json(['data' => $result]);
    }
}
