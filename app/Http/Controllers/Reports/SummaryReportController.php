<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\CompanyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SummaryReportController extends Controller
{
    /**
     * Passport counts over every passport, in the company report's columns,
     * for the dashboard cards of every role.
     */
    public function __invoke(CompanyReport $report): JsonResponse
    {
        Gate::authorize('viewReport', Company::class);

        return response()->json(['data' => $report->overallCounts()]);
    }
}
