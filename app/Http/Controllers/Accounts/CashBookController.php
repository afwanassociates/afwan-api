<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\LedgerRequest;
use App\Http\Requests\Accounts\UpdateCashSettingsRequest;
use App\Models\CashSetting;
use App\Services\CashBook;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The cash book (day by day, with opening and closing cash in hand), the cash
 * in hand today, and the company's cash opening balance.
 */
class CashBookController extends Controller
{
    public function __construct(private readonly CashBook $cashBook) {}

    /**
     * GET /cash-book?from&to — every cash-in-hand entry, grouped by day.
     */
    public function index(LedgerRequest $request): JsonResponse
    {
        Gate::authorize('view', CashSetting::class);

        return response()->json($this->cashBook->report($request->validated('from'), $request->validated('to')));
    }

    /**
     * GET /cash-settings — the opening balance and today's cash in hand.
     */
    public function show(): JsonResponse
    {
        Gate::authorize('view', CashSetting::class);

        return response()->json(['data' => $this->summary(CashSetting::current())]);
    }

    /**
     * PUT /cash-settings — set the opening balance (admin and super_admin).
     */
    public function update(UpdateCashSettingsRequest $request): JsonResponse
    {
        Gate::authorize('update', CashSetting::class);

        $setting = $this->cashBook->setOpening(
            (float) $request->validated('opening_balance'),
            $request->validated('opening_date'),
            $request->user(),
        );

        return response()->json(['data' => $this->summary($setting)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CashSetting $setting): array
    {
        $today = LocalDate::today()->format('Y-m-d');

        return [
            'opening_balance' => (float) $setting->opening_balance,
            'opening_date' => $setting->opening_date?->format('Y-m-d'),
            'today' => $today,
            'cash_in_hand' => $this->cashBook->balanceOn($today),
            // Cash entries waiting for approval (not in cash in hand yet).
            'pending' => $this->cashBook->pendingSummary(),
        ];
    }
}
