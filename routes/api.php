<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\MedicalCenterController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DataEntry\CompanyController;
use App\Http\Controllers\DataEntry\CountryLookupController;
use App\Http\Controllers\DataEntry\DefaultsController;
use App\Http\Controllers\DataEntry\EntryModeController;
use App\Http\Controllers\DataEntry\MedicalCenterLookupController;
use App\Http\Controllers\DataEntry\MedicalDateController;
use App\Http\Controllers\DataEntry\MedicalQueueController;
use App\Http\Controllers\DataEntry\MedicalRecordController;
use App\Http\Controllers\DataEntry\MedicalSlipController;
use App\Http\Controllers\DataEntry\PassportEntryController;
use App\Http\Controllers\DataEntry\PassportScanController;
use App\Http\Controllers\DataEntry\ReferenceController;
use App\Http\Controllers\DataEntry\StepActionController;
use App\Http\Controllers\DataEntry\StepListController;
use App\Http\Controllers\DataEntry\StepMilestoneController;
use App\Http\Controllers\DataEntry\StepRecordController;
use App\Http\Controllers\DataEntry\WorkflowConfigController;
use App\Http\Controllers\DataEntry\WorkflowSummaryController;
use App\Http\Controllers\Reports\CompanyReportController;
use App\Http\Controllers\RoleController;
use App\Support\Workflow;
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
        Route::apiResource('medical-centers', MedicalCenterController::class)->except('show');
        // Full company management (admin and super_admin only).
        Route::apiResource('companies', AdminCompanyController::class);
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
        // The passport scan copy. Admin and super_admin reach these through the
        // role hierarchy; it is the only prefix that serves passport details.
        Route::get('/passports/{passport}/scan', [PassportScanController::class, 'show'])->name('passports.scan.show');
        Route::post('/passports/{passport}/scan', [PassportScanController::class, 'store'])->name('passports.scan.store');
        Route::patch('/passports/{passport}/medical-slip', MedicalSlipController::class)->name('passports.medical-slip');
        Route::patch('/passports/{passport}/medical-date', MedicalDateController::class)->name('passports.medical-date');
        Route::patch('/passports/{passport}/entry-mode', EntryModeController::class)->name('passports.entry-mode');
        Route::post('/passports/{passport}/medical-result', [MedicalRecordController::class, 'result'])->name('passports.medical-result');
        Route::get('/passports/{passport}/medicals', [MedicalRecordController::class, 'index'])->name('passports.medicals.index');
        Route::patch('/medicals/{medical}', [MedicalRecordController::class, 'update'])->name('medicals.update');
        Route::delete('/medicals/{medical}', [MedicalRecordController::class, 'destroy'])->name('medicals.destroy');
        Route::get('/medical/queue', MedicalQueueController::class)->name('medical.queue');
        Route::get('/medical-centers', MedicalCenterLookupController::class)->name('medical-centers.index');
        Route::get('/workflow/summary', WorkflowSummaryController::class)->name('workflow.summary');

        // Steps 3 to 6 (calling, visa, bmet, flight; config/workflow.php). Every
        // write goes through App\Services\WorkflowService.
        Route::get('/workflow/config', WorkflowConfigController::class)->name('workflow.config');
        Route::get('/passports/{passport}/steps', [StepRecordController::class, 'index'])->name('passports.steps.index');
        Route::post('/passports/{passport}/steps/{stepKey}', [StepRecordController::class, 'store'])
            ->whereIn('stepKey', app(Workflow::class)->recordStepKeys())
            ->name('passports.steps.store');
        // The step pages' actions: start a step, then record its result.
        // Calling is saved with one update instead (config single_update).
        Route::post('/passports/{passport}/steps/calling/update', [StepActionController::class, 'updateCalling'])
            ->name('passports.steps.calling.update');
        // Flight: the booking date (alongside BMET), then confirm (needs both).
        Route::post('/passports/{passport}/steps/flight/booking', [StepActionController::class, 'bookFlight'])
            ->name('passports.steps.flight.booking');
        Route::delete('/passports/{passport}/steps/flight/booking', [StepActionController::class, 'clearFlightBooking'])
            ->name('passports.steps.flight.booking.clear');
        Route::post('/passports/{passport}/steps/flight/confirm', [StepActionController::class, 'confirmFlight'])
            ->name('passports.steps.flight.confirm');
        Route::delete('/passports/{passport}/steps/flight/confirm', [StepActionController::class, 'undoFlight'])
            ->name('passports.steps.flight.undo');
        // BMET: Training done, Finger done (any order), then BMET clearance done.
        Route::post('/passports/{passport}/steps/{step}/{action}', [StepMilestoneController::class, 'store'])
            ->whereIn('step', app(Workflow::class)->milestoneStepKeys())
            ->whereIn('action', app(Workflow::class)->milestoneActionKeys())
            ->name('passports.steps.milestones.store');
        Route::delete('/passports/{passport}/steps/{step}/{action}', [StepMilestoneController::class, 'destroy'])
            ->whereIn('step', app(Workflow::class)->milestoneStepKeys())
            ->whereIn('action', app(Workflow::class)->milestoneActionKeys())
            ->name('passports.steps.milestones.destroy');
        Route::match(['post', 'patch'], '/passports/{passport}/steps/{step}/start', [StepActionController::class, 'start'])
            ->whereIn('step', app(Workflow::class)->startResultStepKeys())
            ->name('passports.steps.start');
        Route::post('/passports/{passport}/steps/{step}/result', [StepActionController::class, 'result'])
            ->whereIn('step', app(Workflow::class)->startResultStepKeys())
            ->name('passports.steps.result');
        Route::patch('/step-records/{stepRecord}', [StepRecordController::class, 'update'])->name('step-records.update');
        Route::delete('/step-records/{stepRecord}', [StepRecordController::class, 'destroy'])->name('step-records.destroy');

        Route::apiResource('references', ReferenceController::class)->except('show');
        // Search dropdown and Quick Add for data entry; editing and deleting are
        // admin-only and kept here for older clients (same as /admin/companies).
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store']);
        Route::patch('/companies/{company}', [AdminCompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [AdminCompanyController::class, 'destroy'])->name('companies.destroy');
        Route::get('/countries', CountryLookupController::class)->name('countries.index');
        Route::get('/defaults', DefaultsController::class)->name('defaults');
    });

    // The step work lists, readable by accounts too (actions stay data-entry only).
    Route::prefix('data-entry')->name('data-entry.')->middleware('role:data_entry,accounts')->group(function () {
        Route::get('/steps/{step}/passports', StepListController::class)
            ->whereIn('step', app(Workflow::class)->recordStepKeys())
            ->name('steps.passports');
    });

    // Read-only reports for data entry and accounts (admin and super_admin pass too).
    Route::prefix('reports')->name('reports.')->middleware('role:data_entry,accounts')->group(function () {
        Route::get('/companies', [CompanyReportController::class, 'index'])->name('companies.index');
        Route::get('/companies/{company}/passports', [CompanyReportController::class, 'passports'])->name('companies.passports');
    });

    Route::prefix('accounts')->name('accounts.')->middleware('role:accounts')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'accounts',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });
});
