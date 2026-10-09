<?php

use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DataEntry\CompanyController;
use App\Http\Controllers\DataEntry\CountryLookupController;
use App\Http\Controllers\DataEntry\DefaultsController;
use App\Http\Controllers\DataEntry\MedicalQueueController;
use App\Http\Controllers\DataEntry\MedicalRecordController;
use App\Http\Controllers\DataEntry\PassportEntryController;
use App\Http\Controllers\DataEntry\ReferenceController;
use App\Http\Controllers\DataEntry\WorkflowSummaryController;
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

        // Countries are only ever added or deactivated, never deleted.
        Route::apiResource('countries', CountryController::class)->only(['index', 'store', 'update']);
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    Route::prefix('data-entry')->name('data-entry.')->middleware('role:data_entry')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'data-entry',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');

        // Passport records. Who may edit or delete is decided by the policies.
        Route::apiResource('passports', PassportEntryController::class)
            ->parameters(['passports' => 'passport']);

        // Step 2: medical results. Every write goes through App\Services\MedicalService.
        Route::get('/passports/{passport}/medicals', [MedicalRecordController::class, 'index'])->name('passports.medicals.index');
        Route::post('/passports/{passport}/medicals', [MedicalRecordController::class, 'store'])->name('passports.medicals.store');
        Route::patch('/medicals/{medical}', [MedicalRecordController::class, 'update'])->name('medicals.update');
        Route::delete('/medicals/{medical}', [MedicalRecordController::class, 'destroy'])->name('medicals.destroy');
        Route::get('/medical/queue', MedicalQueueController::class)->name('medical.queue');
        Route::get('/workflow/summary', WorkflowSummaryController::class)->name('workflow.summary');

        Route::apiResource('references', ReferenceController::class)->except('show');
        Route::apiResource('companies', CompanyController::class)->except('show');
        Route::get('/countries', CountryLookupController::class)->name('countries.index');
        Route::get('/defaults', DefaultsController::class)->name('defaults');
    });

    Route::prefix('accounts')->name('accounts.')->middleware('role:accounts')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'accounts',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });
});
