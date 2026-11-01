<?php

use App\Models\CashSetting;
use App\Models\Party;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * A starting balance saved before the opening balance became automatic (nobody can set
 * one any more): it still counts.
 */
function setOpening(float $amount, string $date): void
{
    CashSetting::current()->forceFill(['opening_balance' => $amount, 'opening_date' => $date])->save();
}

function receipt(Party $party, float $amount, string $date, string $method = 'cash'): TestResponse
{
    return test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => $party->type->value, 'party_id' => $party->id,
        'business_type' => 'recruitment', 'country_code' => 'MY',
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => $method,
        'bank_name' => 'Sonali', 'bank_branch' => 'Motijheel', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567',
    ]);
}

function transfer(string $direction, float $amount, string $date): TestResponse
{
    return test()->postJson('/api/accounts/cash-transfers', [
        'date' => $date, 'direction' => $direction, 'amount' => $amount,
        'bank_name' => ' Dutch  Bangla ', 'bank_branch' => 'Uttara',
        'cheque_no' => 'CHQ-500', 'deposit_slip_no' => 'DS-1001',
        'reference_name' => 'Abdul Karim', 'reference_mobile' => '01711000000', 'reference_nid_passport' => 'A1234567',
    ]);
}

describe('cash opening balance', function () {
    it('cannot be set by anyone: the opening is worked out, and read by the accounts head', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->putJson('/api/accounts/cash-settings', ['opening_balance' => 1, 'opening_date' => '2026-10-01'])
            ->assertMethodNotAllowed();
        setOpening(10000, '2026-10-01');

        $this->getJson('/api/finance/summary')->assertOk()
            ->assertJsonPath('data.cash_in_hand', 10000)
            // Today's opening is yesterday's closing (the starting balance, nothing since).
            ->assertJsonPath('data.opening_today', 10000);

        // Accounts users get the opening date only, never the balances or the summary.
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/accounts/cash-settings')
            ->assertOk()
            ->assertJsonPath('data.opening_date', '2026-10-01')
            ->assertJsonMissingPath('data.opening_balance')
            ->assertJsonMissingPath('data.cash_in_hand')
            ->assertJsonMissingPath('data.total_received');
        $this->getJson('/api/finance/summary')->assertForbidden();

        // Admins see the summary on the dashboard, but have no cash pages.
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $admin) {
            Sanctum::actingAs($admin);
            $this->getJson('/api/finance/summary')
                ->assertOk()
                ->assertJsonPath('data.cash_in_hand', 10000)
                ->assertJsonPath('data.opening_balance', 10000)
                ->assertJsonMissingPath('data.pending');
            $this->getJson('/api/accounts/cash-settings')->assertForbidden();
            }

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->getJson('/api/accounts/cash-settings')->assertForbidden();
        $this->getJson('/api/finance/summary')->assertForbidden();
        $this->getJson('/api/accounts/cash-book')->assertForbidden();
    });

    it('refuses cash entries before a saved opening date', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create();
        setOpening(500, '2026-09-01');

        receipt($party, 100, '2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Cash entries start on the cash opening date (01-09-2026). Choose that date or later.']);
        // A bank receipt never passes through the cash box, so it may be dated earlier.
        receipt($party, 100, '2026-08-31', 'bank')->assertCreated();
        transfer('withdrawal', 100, '2026-08-31')->assertUnprocessable()->assertJsonValidationErrors('date');
    });

});

describe('cash transfers', function () {
    it('deposits cash to a bank and withdraws it, numbered per year', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        transfer('deposit', 1, '2026-10-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Not enough cash in hand: the balance would be −৳1.00 on 02-10-2026.']);

        transfer('withdrawal', 2000, '2026-10-02')
            ->assertCreated()
            ->assertJsonPath('data.transfer_no', 'CT-2026-0001')
            ->assertJsonPath('data.bank_name', 'Dutch Bangla')
            ->assertJsonPath('data.direction_label', 'Cash withdrawn from bank');
        // The withdrawal is pending, so its cash is not in hand yet.
        transfer('deposit', 1500, '2026-10-03')->assertUnprocessable()->assertJsonValidationErrors('amount');
        approveAllAccountsEntries();
        $id = transfer('deposit', 1500, '2026-10-03')->assertCreated()->json('data.id');
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/cash-transfers')
            ->assertOk()
            ->assertJsonPath('meta.totals', ['count' => 2, 'deposit' => 1500, 'withdrawal' => 2000]);
        $this->getJson('/api/accounts/cash-transfers?direction=deposit&q=uttara')->assertJsonPath('data.*.id', [$id]);

        // Approved: locked.
        $this->putJson("/api/accounts/cash-transfers/{$id}", ['date' => '2026-10-03', 'direction' => 'deposit', 'amount' => 2000, 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'deposit_slip_no' => 'DS-9', 'reference_name' => 'Karim', 'reference_mobile' => '01711000000', 'reference_nid_passport' => 'A1'])
            ->assertForbidden();
        $this->deleteJson("/api/accounts/cash-transfers/{$id}")->assertForbidden();
        $this->postJson('/api/accounts/cash-transfers', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'direction', 'amount', 'bank_name', 'bank_branch']);
    });

    it('needs the deposit slip and depositor for a deposit', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        transfer('withdrawal', 5000, '2026-10-01')->assertCreated()
            // A withdrawal keeps its cheque number and who withdrew it, no deposit details.
            ->assertJsonPath('data.cheque_no', 'CHQ-500')
            ->assertJsonPath('data.reference_name', 'Abdul Karim')
            ->assertJsonPath('data.reference_mobile', '01711000000')
            ->assertJsonPath('data.reference_nid_passport', 'A1234567')
            ->assertJsonPath('data.deposit_slip_no', null);
        approveAllAccountsEntries();

        $deposit = ['date' => '2026-10-02', 'direction' => 'deposit', 'amount' => 1000, 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara'];
        $this->postJson('/api/accounts/cash-transfers', $deposit)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'deposit_slip_no' => 'Enter the deposit slip number.',
                'reference_name' => 'Enter the depositor\'s name.',
                'reference_mobile' => 'Enter the depositor\'s mobile number.',
                'reference_nid_passport' => 'Enter the depositor\'s NID or passport number.',
            ]);
        $this->postJson('/api/accounts/cash-transfers', [...$deposit, 'deposit_slip_no' => 'DS-1', 'reference_mobile' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_mobile']);

        $this->postJson('/api/accounts/cash-transfers', [
            ...$deposit, 'deposit_slip_no' => ' DS  2231 ',
            'reference_name' => ' Abdul  Karim ', 'reference_mobile' => '01711-000000', 'reference_nid_passport' => ' a1234 567 ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.deposit_slip_no', 'DS 2231')
            ->assertJsonMissingPath('data.transaction_type')
            ->assertJsonPath('data.reference_name', 'Abdul Karim')
            ->assertJsonPath('data.reference_mobile', '01711-000000')
            ->assertJsonPath('data.reference_nid_passport', 'A1234567')
            // A deposit keeps no cheque number.
            ->assertJsonPath('data.cheque_no', null);
        $this->getJson('/api/accounts/cash-transfers?q=2231')->assertJsonPath('meta.total', 1);

        $this->postJson('/api/accounts/cash-transfers', [...$deposit, 'direction' => 'withdrawal', 'cheque_no' => ' '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cheque_no' => 'Enter the cheque number.',
                'reference_name' => 'Enter the withdrawer\'s name.',
                'reference_mobile' => 'Enter the withdrawer\'s mobile number.',
                'reference_nid_passport' => 'Enter the withdrawer\'s NID or passport number.',
            ]);
        $this->getJson('/api/accounts/cash-transfers?q=CHQ-500')->assertJsonPath('meta.total', 1);
    });

    it('moves cash in to and out of a mobile banking wallet', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        transfer('withdrawal', 5000, '2026-10-01')->assertCreated();
        approveAllAccountsEntries();
        $mobile = fn (string $direction, float $amount, array $more = []) => $this->postJson('/api/accounts/cash-transfers', [
            'date' => '2026-10-02', 'direction' => $direction, 'channel' => 'mobile_banking', 'amount' => $amount,
            'mobile_provider' => 'bkash', 'mobile_number' => '+880 1712-345678',
            'reference_name' => 'Abdul Karim', 'reference_mobile' => '01711000000',
            // Bank details are dropped for mobile banking.
            'bank_name' => 'Ignored', 'cheque_no' => 'X', 'deposit_slip_no' => 'X',
            ...$more,
        ]);

        // A wallet needs the service and number; no bank, slip or NID.
        $this->postJson('/api/accounts/cash-transfers', ['date' => '2026-10-02', 'direction' => 'deposit', 'channel' => 'mobile_banking', 'amount' => 100, 'reference_name' => 'K', 'reference_mobile' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_provider', 'mobile_number'])
            ->assertJsonMissingValidationErrors(['bank_name', 'bank_branch', 'deposit_slip_no', 'reference_nid_passport']);
        // Cash in needs the cash in hand, like a bank deposit.
        $mobile('deposit', 9000)->assertUnprocessable()->assertJsonValidationErrors('amount');

        $mobile('deposit', 3000)
            ->assertCreated()
            ->assertJsonPath('data.channel', 'mobile_banking')
            ->assertJsonPath('data.direction_label', 'Cash in to mobile banking')
            ->assertJsonPath('data.mobile_provider_label', 'bKash')
            ->assertJsonPath('data.mobile_number', '01712345678')
            ->assertJsonPath('data.bank_name', null)
            ->assertJsonPath('data.deposit_slip_no', null);
        $mobile('withdrawal', 1000)->assertCreated()->assertJsonPath('data.direction_label', 'Cash out from mobile banking');
        approveAllAccountsEntries();

        // Cash 5000 − 3000 + 1000; the wallet 3000 − 1000; the bank only lost the 5000 withdrawn.
        financeSummary()
            ->assertJsonPath('data.cash_in_hand', 3000)
            ->assertJsonPath('data.mobile_banking_balance', 2000)
            ->assertJsonPath('data.bank_balance', -5000)
            ->assertJsonPath('data.total_received', 0)
            ->assertJsonPath('data.total_paid', 0);
        $this->getJson('/api/accounts/cash-book?from=2026-10-02&to=2026-10-02')
            ->assertJsonPath('days.0.lines.*.particulars', ['Deposited to bKash 01712345678', 'Withdrawn from bKash 01712345678']);
        $this->getJson('/api/accounts/cash-transfers?q=01712345678')->assertJsonCount(2, 'data');
    });

    it('refuses returning a withdrawal or receipt whose cash was spent later', function () {
        Sanctum::actingAs($head = User::factory()->accountsHead()->create());
        $party = Party::factory()->create();
        $withdrawal = transfer('withdrawal', 1000, '2026-10-01')->json('data.id');
        $receiptId = receipt($party, 500, '2026-10-02')->json('data.id');
        approveAllAccountsEntries();
        transfer('deposit', 1200, '2026-10-03')->assertCreated();
        approveAllAccountsEntries();

        // Returning an approved entry is for admins.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/accounts/approvals/cash-transfers/{$withdrawal}/return", ['reason' => 'Wrong bank'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Not enough cash in hand: the balance would be −৳700.00 on 03-10-2026.']);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$receiptId}/return", ['reason' => 'Wrong party'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Not enough cash in hand: the balance would be −৳200.00 on 03-10-2026.']);

        Sanctum::actingAs($head);
        financeSummary('/api/accounts/cash-settings')
            ->assertJsonPath('data.cash_in_hand', 300)
            ->assertJsonPath('data.pending', ['count' => 0, 'in' => 0, 'out' => 0]);
    });
});

describe('cash book', function () {
    it("opens each day with the previous day's closing cash, worked out automatically", function () {
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Dhaka')->utc());
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create();
        setOpening(1000, '2026-10-01');

        receipt($party, 500, '2026-10-05')->assertCreated();
        // Today's receipt and a bank receipt do not change today's opening.
        receipt($party, 200, '2026-10-06')->assertCreated();
        receipt($party, 9000, '2026-10-05', 'bank')->assertCreated();
        approveAllAccountsEntries();

        financeSummary()
            ->assertJsonPath('data.opening_balance', 1000)
            ->assertJsonPath('data.opening_today', 1500)
            ->assertJsonPath('data.cash_in_hand', 1700);
        $this->getJson('/api/accounts/cash-book?from=2026-10-06&to=2026-10-06')
            ->assertJsonPath('days.0.opening', 1500);
    });

    it('shows each day with its opening, entries, running balance and closing', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create(['name' => 'Karim', 'code' => null]);
        setOpening(1000, '2026-10-01');

        receipt($party, 500, '2026-10-01')->assertCreated();
        receipt($party, 9999, '2026-10-01', 'bank')->assertCreated();
        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-02', 'payee_type' => 'other', 'payee_name' => 'Landlord', 'payment_type' => 'expense',
            'expense_category' => 'office_rent', 'purpose' => 'October rent', 'bill_no' => 'R-17', 'amount' => 300, 'payment_method' => 'cash',
        ])->assertCreated();
        transfer('deposit', 700, '2026-10-02')->assertCreated();
        transfer('withdrawal', 250, '2026-10-04')->assertCreated();

        // Nothing is in the book until approved.
        $this->getJson('/api/accounts/cash-book')->assertJsonPath('days', [])->assertJsonPath('closing_balance', 1000);
        // The bank receipt is not cash in hand: it is left out.
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.pending', ['count' => 4, 'in' => 750, 'out' => 1000]);
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/cash-book')
            ->assertOk()
            ->assertJsonPath('opening_balance', 1000)
            ->assertJsonPath('days.*.date', ['2026-10-01', '2026-10-02', '2026-10-04'])
            ->assertJsonPath('days.*.opening', [1000, 1500, 500])
            ->assertJsonPath('days.*.closing', [1500, 500, 750])
            ->assertJsonPath('days.0.lines.0.reference', 'CR-2026-0001')
            ->assertJsonPath('days.0.lines.0.particulars', "Received from Karim ({$party->code})")
            ->assertJsonCount(1, 'days.0.lines')
            ->assertJsonPath('days.1.lines.*.kind', ['payment', 'transfer'])
            ->assertJsonPath('days.1.lines.*.balance', [1200, 500])
            ->assertJsonPath('days.1.lines.0.detail', 'Office rent · October rent · Bill no. R-17')
            ->assertJsonPath('days.1.lines.1.particulars', 'Deposited to Dutch Bangla, Uttara')
            ->assertJsonPath('days.1.in', 0)
            ->assertJsonPath('days.1.out', 1000)
            ->assertJsonPath('totals', ['in' => 750, 'out' => 1000])
            ->assertJsonPath('closing_balance', 750);

        // The bank receipt and the deposit are in the bank; the withdrawal came out of it.
        financeSummary()
            ->assertJsonPath('data.total_received', 500 + 9999)
            ->assertJsonPath('data.total_paid', 300)
            ->assertJsonPath('data.cash_in_hand', 750)
            ->assertJsonPath('data.bank_balance', 9999 + 700 - 250)
            ->assertJsonPath('data.mobile_banking_balance', 0);

        // From a date: earlier days are brought forward.
        $this->getJson('/api/accounts/cash-book?from=2026-10-02&to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('opening_balance', 1500)
            ->assertJsonPath('days.*.date', ['2026-10-02'])
            ->assertJsonPath('closing_balance', 500);

        $this->getJson('/api/accounts/cash-book?from=2026-10-05&to=2026-10-01')->assertUnprocessable();
    });
});
