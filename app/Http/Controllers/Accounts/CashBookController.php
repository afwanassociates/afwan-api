<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Enums\CashPaymentType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\LedgerRequest;
use App\Models\CashSetting;
use App\Services\CashBook;
use App\Services\ExpenseReport;
use App\Support\LocalDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The cash book (day by day, with opening and closing cash in hand), the cash
 * in hand today, and the company's cash opening balance.
 */
class CashBookController extends Controller
{
    public function __construct(private readonly CashBook $cashBook, private readonly ExpenseReport $expenses) {}

    /**
     * GET /cash-book?book&from&to — every entry of one book (cash in hand by
     * default, or bank / mobile_banking / all), grouped by day.
     */
    public function index(LedgerRequest $request): JsonResponse
    {
        Gate::authorize('view', CashSetting::class);

        return response()->json($this->cashBook->report(
            $request->validated('from'),
            $request->validated('to'),
            // "all": cash in hand, bank and mobile banking together.
            $request->validated('book') === 'all'
                ? null
                : PaymentMethod::tryFrom((string) $request->validated('book')) ?? PaymentMethod::Cash,
        ));
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
     * Approved payments up to the day by what they were for (they add up to total_paid).
     *
     * @return array{expenses: float, refunds: float, owner_loans: float}
     */
    private function paidBreakdown(string $date): array
    {
        $sums = DB::table('cash_payments')
            ->where('status', ApprovalStatus::Approved->value)
            ->where('date', '<=', $date)
            ->selectRaw('payment_type, SUM(amount) as amount')
            ->groupBy('payment_type')
            ->pluck('amount', 'payment_type');
        $sum = fn (CashPaymentType $type) => round((float) ($sums[$type->value] ?? 0), 2);

        return [
            'expenses' => $sum(CashPaymentType::Expense),
            'refunds' => $sum(CashPaymentType::Refund),
            'owner_loans' => $sum(CashPaymentType::OwnerLoan),
        ];
    }

    private static function paisa(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Total received and paid so far, today's opening (yesterday's closing cash,
     * bank and mobile banking together), and today's closing cash, bank and
     * mobile banking balances (approved entries only).
     *
     * @return array<string, mixed>
     */
    private function money(CashSetting $setting): array
    {
        $today = LocalDate::today()->format('Y-m-d');
        $yesterday = LocalDate::today()->subDay()->format('Y-m-d');
        // Today's opening cash: yesterday's closing cash in hand + bank + mobile banking,
        // worked out (never typed), in paisa so the sum never drifts.
        $openingToday = round(
            self::paisa($this->cashBook->balanceOn($yesterday))
            + self::paisa($this->cashBook->bankBalanceOn($yesterday))
            + self::paisa($this->cashBook->mobileBankingBalanceOn($yesterday))
        ) / 100;

        return [
            'opening_balance' => (float) $setting->opening_balance,
            'opening_date' => $setting->opening_date?->format('Y-m-d'),
            'today' => $today,
            'opening_today' => $openingToday,
            'total_received' => $this->cashBook->totalOn('cash_receipts', $today),
            'total_paid' => $this->cashBook->totalOn('cash_payments', $today),
            // What the money paid was for: a loan to an owner is an advance, not spending.
            'paid_breakdown' => $this->paidBreakdown($today),
            // What the company spent: expense payments and approved owner loan bills.
            'total_expenses' => $this->expenses->totalOn($today),
            'cash_in_hand' => $this->cashBook->balanceOn($today),
            'bank_balance' => $this->cashBook->bankBalanceOn($today),
            'mobile_banking_balance' => $this->cashBook->mobileBankingBalanceOn($today),
        ];
    }
}
