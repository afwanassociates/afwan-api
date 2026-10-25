<?php

use App\Models\Party;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function setOpening(float $amount, string $date): TestResponse
{
    return test()->putJson('/api/accounts/cash-settings', ['opening_balance' => $amount, 'opening_date' => $date]);
}

function receipt(Party $party, float $amount, string $date, string $method = 'cash'): TestResponse
{
    return test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => $party->type->value, 'party_id' => $party->id,
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => $method,
        'bank_name' => 'Sonali', 'bank_branch' => 'Motijheel',
    ]);
}

function transfer(string $direction, float $amount, string $date): TestResponse
{
    return test()->postJson('/api/accounts/cash-transfers', [
        'date' => $date, 'direction' => $direction, 'amount' => $amount,
        'bank_name' => ' Dutch  Bangla ', 'bank_branch' => 'Uttara',
    ]);
}

describe('cash opening balance', function () {
    it('is set by the accounts head and read by accounts, with today\'s cash in hand', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        setOpening(10000, '2026-10-01')
            ->assertOk()
            ->assertJsonPath('data.opening_balance', 10000)
            ->assertJsonPath('data.opening_date', '2026-10-01')
            ->assertJsonPath('data.cash_in_hand', 10000);

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/accounts/cash-settings')->assertOk()->assertJsonPath('data.opening_balance', 10000);
        setOpening(1, '2026-10-01')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/accounts/cash-settings')->assertForbidden();
        setOpening(1, '2026-10-01')->assertForbidden();

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->getJson('/api/accounts/cash-settings')->assertForbidden();
        $this->getJson('/api/accounts/cash-book')->assertForbidden();
    });

    it('refuses cash entries before the opening date, and a date after existing entries', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create();

        receipt($party, 100, '2026-09-20')->assertCreated();
        setOpening(500, '2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_date' => 'There are cash entries from 20-09-2026. Choose that date or an earlier one.']);
        setOpening(500, '2026-09-01')->assertOk();

        receipt($party, 100, '2026-08-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Cash entries start on the cash opening date (01-09-2026). Choose that date or later.']);
        // A bank receipt is not a cash-in-hand entry.
        receipt($party, 100, '2026-08-31', 'bank')->assertCreated();
        transfer('withdrawal', 100, '2026-08-31')->assertUnprocessable()->assertJsonValidationErrors('date');
    });

    it('refuses lowering the opening balance below what was spent', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        setOpening(1000, '2026-10-01')->assertOk();
        transfer('deposit', 800, '2026-10-05')->assertCreated();
        approveAllAccountsEntries();

        setOpening(500, '2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_balance' => 'Not enough cash in hand: the balance would be −৳300.00 on 05-10-2026.']);
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.opening_balance', 1000);
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
        $this->putJson("/api/accounts/cash-transfers/{$id}", ['date' => '2026-10-03', 'direction' => 'deposit', 'amount' => 2000, 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara'])
            ->assertForbidden();
        $this->deleteJson("/api/accounts/cash-transfers/{$id}")->assertForbidden();
        $this->postJson('/api/accounts/cash-transfers', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'direction', 'amount', 'bank_name', 'bank_branch']);
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
        $this->getJson('/api/accounts/cash-settings')
            ->assertJsonPath('data.cash_in_hand', 300)
            ->assertJsonPath('data.pending', ['count' => 0, 'in' => 0, 'out' => 0]);
    });
});

describe('cash book', function () {
    it('shows each day with its opening, entries, running balance and closing', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create(['name' => 'Karim', 'code' => null]);
        setOpening(1000, '2026-10-01')->assertOk();

        receipt($party, 500, '2026-10-01')->assertCreated();
        receipt($party, 9999, '2026-10-01', 'bank')->assertCreated();
        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-02', 'payee_type' => 'other', 'payee_name' => 'Landlord', 'payment_type' => 'expense',
            'purpose' => 'Office rent', 'amount' => 300, 'payment_method' => 'cash',
        ])->assertCreated();
        transfer('deposit', 700, '2026-10-02')->assertCreated();
        transfer('withdrawal', 250, '2026-10-04')->assertCreated();

        // Nothing is in the book until approved.
        $this->getJson('/api/accounts/cash-book')->assertJsonPath('days', [])->assertJsonPath('closing_balance', 1000);
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
            ->assertJsonPath('days.1.lines.*.kind', ['payment', 'transfer'])
            ->assertJsonPath('days.1.lines.*.balance', [1200, 500])
            ->assertJsonPath('days.1.lines.0.detail', 'Expense · Office rent')
            ->assertJsonPath('days.1.lines.1.particulars', 'Deposited to Dutch Bangla, Uttara')
            ->assertJsonPath('days.1.in', 0)
            ->assertJsonPath('days.1.out', 1000)
            ->assertJsonPath('totals', ['in' => 750, 'out' => 1000])
            ->assertJsonPath('closing_balance', 750);

        // From a date: earlier days are brought forward.
        $this->getJson('/api/accounts/cash-book?from=2026-10-02&to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('opening_balance', 1500)
            ->assertJsonPath('days.*.date', ['2026-10-02'])
            ->assertJsonPath('closing_balance', 500);

        $this->getJson('/api/accounts/cash-book?from=2026-10-05&to=2026-10-01')->assertUnprocessable();
    });
});
