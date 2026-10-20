<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Log the user in and start a session (Sanctum SPA cookie authentication).
     */
    public function login(LoginRequest $request): UserResource
    {
        $user = $request->authenticate();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new UserResource($user);
    }

    /**
     * Log the user out and end the session.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * The currently authenticated user.
     */
    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
