<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\DataReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Settings → Danger zone: delete all data, for the super admin only (the route group
 * "role:super_admin" and the check below). Every reset writes a backup first.
 */
class DataResetController extends Controller
{
    public function __construct(private readonly DataReset $reset) {}

    /**
     * GET /super-admin/data-reset — the groups with their row counts, and the backups.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => [
            'groups' => $this->reset->summary(),
            'backups' => $this->reset->backups(),
            'confirmation' => DataReset::CONFIRMATION,
        ]]);
    }

    /**
     * POST /super-admin/data-reset — back up, then delete the chosen groups.
     * Needs the confirmation words and the super admin's own password.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['string', Rule::in(array_keys(DataReset::GROUPS))],
            'confirmation' => ['required', 'string', Rule::in([DataReset::CONFIRMATION])],
            'password' => ['required', 'string'],
        ], [
            'groups.required' => 'Choose at least one group to delete.',
            'groups.min' => 'Choose at least one group to delete.',
            'confirmation.required' => 'Type '.DataReset::CONFIRMATION.' to confirm.',
            'confirmation.in' => 'Type exactly '.DataReset::CONFIRMATION.' (in capital letters) to confirm.',
            'password.required' => 'Enter your password.',
        ]);

        if (! Hash::check($validated['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is not correct.']);
        }

        $result = $this->reset->reset($validated['groups'], $request->user());

        return response()->json(['data' => [
            ...$result,
            'groups' => $this->reset->summary(),
            'backups' => $this->reset->backups(),
        ]]);
    }

    /**
     * GET /super-admin/data-reset/backups/{name} — download a backup.
     */
    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->authorizeSuperAdmin($request);

        $path = $this->reset->backupPath($name) ?? abort(404);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    /**
     * DELETE /super-admin/data-reset/backups/{name}
     */
    public function deleteBackup(Request $request, string $name): Response
    {
        $this->authorizeSuperAdmin($request);

        abort_unless($this->reset->deleteBackup($name), 404);

        return response()->noContent();
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::SuperAdmin), 403, 'Only a super admin can delete all data.');
    }
}
