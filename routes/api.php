<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\RoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Staff authentication (Sanctum SPA cookies). The SPA first calls
| GET /sanctum/csrf-cookie, then POST /api/login.
*/
Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // "role" with no arguments: any active user.
    Route::middleware('role')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::get('/roles', RoleController::class)->name('roles');
    });

    /*
    | Work areas. A role also passes the groups of every role it outranks,
    | so super_admin and admin reach the data-entry and accounts areas.
    | To add a role: add an enum case in App\Enums\UserRole and a group here.
    */
    Route::prefix('super-admin')->name('super-admin.')->middleware('role:super_admin')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'super-admin',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'admin',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    });

    Route::prefix('data-entry')->name('data-entry.')->middleware('role:data_entry')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'data-entry',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });

    Route::prefix('accounts')->name('accounts.')->middleware('role:accounts')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'accounts',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });
});
