<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Roles the current user may assign, for the admin form's dropdown.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (UserRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ], $request->user()->role->assignableRoles()),
        ]);
    }
}
