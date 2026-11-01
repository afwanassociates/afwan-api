<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\LedgerRequest;
use App\Models\CashSetting;
use App\Services\CashBook;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * GET /cash-settings — the opening date and today; for the accounts head
     * also the money summary (accounts users don't see the balances).
     */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('view', CashSetting::class);

        return response()->json(['data' => $this->summary(CashSetting::current(), $request)]);
    }

    /**
     * GET /finance/summary — the money summary for the dashboard: the accounts
     * head, admin and super admin (route middleware). Read only.
     */
    public function finance(): JsonResponse
    {
        return response()->json(['data' => $this->money(CashSetting::current())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CashSetting $setting, Request $request): array
    {
        if (! in_array($request->user()->role, UserRole::financeViewers(), true)) {
            return [
                'opening_date' => $setting->opening_date?->format('Y-m-d'),
                'today' => LocalDate::today()->format('Y-m-d'),
            ];
        }

        return [
            ...$this->money($setting),
            // Cash entries waiting for approval (not in cash in hand yet).
            'pending' => $this->cashBook->pendingSummary(),
        ];
    }

    /**
     * Total received and paid so far, and today's closing cash, bank and
     * mobile banking balances (approved entries only).
     *
     * @return array<string, mixed>
     */
    private function money(CashSetting $setting): array
    {
        $today = LocalDate::today()->format('Y-m-d');

        return [
            'opening_balance' => (float) $setting->opening_balance,
            'opening_date' => $setting->opening_date?->format('Y-m-d'),
            'today' => $today,
            // Today's opening cash: yesterday's closing, worked out (never typed).
            'opening_today' => $this->cashBook->balanceOn(LocalDate::today()->subDay()->format('Y-m-d')),
            'total_received' => $this->cashBook->totalOn('cash_receipts', $today),
            'total_paid' => $this->cashBook->totalOn('cash_payments', $today),
            'cash_in_hand' => $this->cashBook->balanceOn($today),
            'bank_balance' => $this->cashBook->bankBalanceOn($today),
            'mobile_banking_balance' => $this->cashBook->mobileBankingBalanceOn($today),
        ];
    }
}
