<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListExpensesRequest;
use App\Models\CashPayment;
use App\Services\ExpenseReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash management › Expenses: what the company spent, from expense payments
 * and approved owner loan bills, with totals per category. export=csv
 * downloads every matching expense.
 */
class ExpenseController extends Controller
{
    public function __invoke(ListExpensesRequest $request, ExpenseReport $report): JsonResponse|StreamedResponse
    {
        Gate::authorize('viewAny', CashPayment::class);

        $filters = $request->safe()->only(['from', 'to', 'category', 'source', 'q']);
        $query = $report->query($filters);

        if ($request->validated('export') === 'csv') {
            return $report->csv($query);
        }

        $page = (clone $query)->paginate($request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (object $row) => ExpenseReport::row($row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'totals' => $report->totals($query),
            ],
        ]);
    }
}
