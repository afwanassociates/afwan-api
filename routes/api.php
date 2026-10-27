<?php

use App\Http\Controllers\Accounts\ApprovalController;
use App\Http\Controllers\Accounts\CashBookController;
use App\Http\Controllers\Accounts\CashPaymentController;
use App\Http\Controllers\Accounts\CashReceiptController;
use App\Http\Controllers\Accounts\CashTransferController;
use App\Http\Controllers\Accounts\PartyController;
use App\Http\Controllers\Accounts\PartyLedgerController;
use App\Http\Controllers\Accounts\PartyTransactionController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\CountryController;
use App\Http\Controllers\Admin\MedicalCenterController;
use App\Http\Controllers\Admin\ReferenceController as AdminReferenceController;
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
use App\Http\Controllers\DataEntry\PassportHistoryController;
use App\Http\Controllers\DataEntry\PassportScanController;
use App\Http\Controllers\DataEntry\ReferenceController;
use App\Http\Controllers\DataEntry\StepActionController;
use App\Http\Controllers\DataEntry\StepBulkController;
use App\Http\Controllers\DataEntry\StepListController;
use App\Http\Controllers\DataEntry\StepMilestoneController;
use App\Http\Controllers\DataEntry\StepRecordController;
use App\Http\Controllers\DataEntry\WorkflowConfigController;
use App\Http\Controllers\DataEntry\WorkflowSummaryController;
use App\Http\Controllers\Reports\CompanyReportController;
use App\Http\Controllers\Reports\ReferenceOptionsController;
use App\Http\Controllers\Reports\ReferenceReportController;
use App\Http\Controllers\Reports\SummaryReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SuperAdmin\DataResetController;
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
    | Work areas, listed per role in UserRole::areas(): super_admin also
    | enters admin; the heads enter their team's area. Admins have neither
    | passport entry nor accounts (only the reports and the approvals).
    | To add a role: add an enum case in App\Enums\UserRole and a group here.
    */
    Route::prefix('super-admin')->name('super-admin.')->middleware('role:super_admin')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'super-admin',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');

        // Settings → Danger zone: delete all data (a backup is written first). The delete
        // needs the typed confirmation and the password, and is limited to 5 tries a minute.
        Route::get('/data-reset', [DataResetController::class, 'show'])->name('data-reset.show');
        Route::post('/data-reset', [DataResetController::class, 'destroy'])
            ->middleware('throttle:5,1')->name('data-reset.destroy');
        Route::get('/data-reset/backups/{name}', [DataResetController::class, 'download'])->name('data-reset.backups.download');
        Route::delete('/data-reset/backups/{name}', [DataResetController::class, 'deleteBackup'])->name('data-reset.backups.destroy');
    });

    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'admin',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');

        // Countries are only ever added or deactivated, never deleted.
        Route::apiResource('countries', CountryController::class)->only(['index', 'store', 'update']);
        Route::apiResource('medical-centers', MedicalCenterController::class)->except('show');
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Full company and reference management: the operations head only (CompanyPolicy and
    // ReferencePolicy "manage"). Admins see companies through the read-only reports.
    Route::prefix('admin')->name('admin.')->middleware('role:operation_head')->group(function () {
        Route::apiResource('companies', AdminCompanyController::class);
        Route::apiResource('references', AdminReferenceController::class)->except('show');
    });

    // Staff accounts: admins manage the heads, accounts and data entry users; the accounts head
    // manages accounts users and the operations head data entry users (App\Policies\UserPolicy).
    Route::prefix('admin')->name('admin.')->middleware('role:admin,accounts_head,operation_head')->group(function () {
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

        // Steps 3 to 6 (calling, visa, bmet, flight; config/workflow.php). Every
        // write goes through App\Services\WorkflowService.
        Route::get('/passports/{passport}/steps', [StepRecordController::class, 'index'])->name('passports.steps.index');
        Route::get('/passports/{passport}/history', PassportHistoryController::class)->name('passports.history');
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
        // Bulk: one action for many passports (each checked as its single button is).
        Route::post('/steps/{step}/bulk', StepBulkController::class)
            ->whereIn('step', app(Workflow::class)->recordStepKeys())
            ->name('steps.bulk');
        Route::match(['post', 'patch'], '/passports/{passport}/steps/{step}/start', [StepActionController::class, 'start'])
            ->whereIn('step', app(Workflow::class)->startResultStepKeys())
            ->name('passports.steps.start');
        Route::post('/passports/{passport}/steps/{step}/result', [StepActionController::class, 'result'])
            ->whereIn('step', app(Workflow::class)->startResultStepKeys())
            ->name('passports.steps.result');
        Route::patch('/step-records/{stepRecord}', [StepRecordController::class, 'update'])->name('step-records.update');
        Route::delete('/step-records/{stepRecord}', [StepRecordController::class, 'destroy'])->name('step-records.destroy');

        // Search dropdowns and Quick Add for the passport team; editing and deleting are
        // for the operations head and kept here for older clients (same as /admin/...).
        Route::apiResource('references', ReferenceController::class)->only(['index', 'store']);
        Route::patch('/references/{reference}', [AdminReferenceController::class, 'update'])->name('references.update');
        Route::delete('/references/{reference}', [AdminReferenceController::class, 'destroy'])->name('references.destroy');
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store']);
        Route::patch('/companies/{company}', [AdminCompanyController::class, 'update'])->name('companies.update');
        Route::delete('/companies/{company}', [AdminCompanyController::class, 'destroy'])->name('companies.destroy');
    });

    // Country list and default countries: data entry, and admins for the company form and
    // settings (admins no longer enter the data-entry area itself).
    Route::prefix('data-entry')->name('data-entry.')->middleware('role:data_entry,admin')->group(function () {
        Route::get('/countries', CountryLookupController::class)->name('countries.index');
        Route::get('/defaults', DefaultsController::class)->name('defaults');
    });

    // The step work lists and the step bar (its counts and step definitions), readable by
    // every staff role for the read-only reports (actions stay with the passport team).
    Route::prefix('data-entry')->name('data-entry.')->middleware('role:data_entry,accounts,admin')->group(function () {
        Route::get('/workflow/summary', WorkflowSummaryController::class)->name('workflow.summary');
        Route::get('/workflow/config', WorkflowConfigController::class)->name('workflow.config');
        Route::get('/steps/{step}/passports', StepListController::class)
            ->whereIn('step', app(Workflow::class)->recordStepKeys())
            ->name('steps.passports');
    });

    // Read-only reports for data entry, accounts, admin and super_admin.
    Route::prefix('reports')->name('reports.')->middleware('role:data_entry,accounts,admin')->group(function () {
        // Passport counts over every passport, for the dashboard cards.
        Route::get('/summary', SummaryReportController::class)->name('summary');
        Route::get('/companies', [CompanyReportController::class, 'index'])->name('companies.index');
        Route::get('/companies/{company}/passports', [CompanyReportController::class, 'passports'])->name('companies.passports');
        // The All Passports overview (lean, read only; same filters as view=overview).
        Route::get('/passports', [PassportEntryController::class, 'index'])->name('passports');
        // Options of the company report's "Filter by reference" dropdown.
        Route::get('/references', ReferenceOptionsController::class)->name('references.index');
        // The reference-wise report (like the company report, without quota or balance).
        Route::get('/reference-report', [ReferenceReportController::class, 'index'])->name('reference-report.index');
        Route::get('/reference-report/{reference}/passports', [ReferenceReportController::class, 'passports'])->name('reference-report.passports');
    });

    Route::prefix('operation-head')->name('operation-head.')->middleware('role:operation_head')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'operation-head',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });

    Route::prefix('accounts-head')->name('accounts-head.')->middleware('role:accounts_head')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'accounts-head',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');
    });

    // Approval (Prepared → Approved): only approved entries count in the books. The accounts
    // team sees its own entries; approvers are the accounts head, admin and super admin
    // (App\Policies\AccountsEntryPolicy). Admins reach accounts only through this page.
    Route::prefix('accounts')->name('accounts.')->middleware('role:accounts,admin')->group(function () {
        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('/approvals/summary', [ApprovalController::class, 'summary'])->name('approvals.summary');
        Route::post('/approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])
            ->where(['type' => 'cash-receipts|cash-payments|cash-transfers|party-transactions', 'id' => '[0-9]+'])->name('approvals.approve');
        Route::post('/approvals/{type}/{id}/return', [ApprovalController::class, 'sendBack'])
            ->where(['type' => 'cash-receipts|cash-payments|cash-transfers|party-transactions', 'id' => '[0-9]+'])->name('approvals.return');
    });

    // Parties and cash: the accounts team only (accounts and the accounts head).
    Route::prefix('accounts')->name('accounts.')->middleware('role:accounts')->group(function () {
        Route::get('/dashboard', fn (Request $request) => response()->json([
            'area' => 'accounts',
            'message' => "Welcome, {$request->user()->name}.",
        ]))->name('dashboard');

        // Party / client management (clients and owners) and their ledgers.
        Route::apiResource('parties', PartyController::class);
        Route::get('/parties/{party}/ledger', PartyLedgerController::class)->name('parties.ledger');
        // Receivable and refund lines entered by hand (payments come from cash receipts).
        Route::post('/parties/{party}/transactions', [PartyTransactionController::class, 'store'])->name('parties.transactions.store');
        Route::patch('/party-transactions/{transaction}', [PartyTransactionController::class, 'update'])->name('party-transactions.update');
        Route::delete('/party-transactions/{transaction}', [PartyTransactionController::class, 'destroy'])->name('party-transactions.destroy');

        // Cash management. Every cash write runs through App\Services\CashBook, so cash in
        // hand never goes negative on any day.
        Route::apiResource('cash-receipts', CashReceiptController::class);
        Route::apiResource('cash-payments', CashPaymentController::class);
        Route::apiResource('cash-transfers', CashTransferController::class);
        Route::get('/cash-book', [CashBookController::class, 'index'])->name('cash-book');
        // The opening cash in hand (set by the accounts head) and today's balance.
        Route::get('/cash-settings', [CashBookController::class, 'show'])->name('cash-settings.show');
        Route::put('/cash-settings', [CashBookController::class, 'update'])->name('cash-settings.update');
    });
});
