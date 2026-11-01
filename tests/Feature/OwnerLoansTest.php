<?php

use App\Models\CashPayment;
use App\Models\Party;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** An owner receipt (cash = capital, or a loan), approved by an accounts head. */
function ownerReceipt(Party $owner, float $amount, string $type = 'loan', string $date = '2026-10-01'): int
{
    $id = test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => 'owner', 'party_id' => $owner->id,
        'receipt_type' => $type, 'amount' => $amount, 'payment_method' => 'cash',
    ])->assertCreated()->json('data.id');
    approveAllAccountsEntries();

    return $id;
}

function repayment(Party $owner, ?int $loanId, float $amount, string $date = '2026-10-05'): TestResponse
{
    return test()->postJson('/api/accounts/cash-payments', [
        'date' => $date, 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'loan_repayment',
        'loan_receipt_id' => $loanId, 'amount' => $amount, 'payment_method' => 'cash',
    ]);
}

describe('owner loans', function () {
    it('lists each owner loan with what is repaid and still owed, and the owners\' capital', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $chairman = Party::factory()->owner()->create(['name' => 'Chairman']);
        $md = Party::factory()->owner()->create(['name' => 'MD']);

        ownerReceipt($chairman, 500000, 'cash');
        ownerReceipt($md, 200000, 'cash');
        $big = ownerReceipt($chairman, 100000);
        $small = ownerReceipt($md, 50000, date: '2026-10-02');

        repayment($chairman, $big, 40000)->assertCreated()->assertJsonPath('data.loan_receipt.id', $big);
        repayment($md, $small, 50000)->assertCreated();
        // A payment to the owner lowers their capital.
        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-06', 'payee_type' => 'owner', 'party_id' => $md->id, 'payment_type' => 'drawing',
            'amount' => 20000, 'payment_method' => 'cash',
        ])->assertCreated();

        // Pending repayments are not repaid yet.
        $this->getJson('/api/accounts/owner-loans')
            ->assertOk()
            ->assertJsonPath('summary.loans_owed', 150000)
            ->assertJsonPath('data.1.pending_repaid', 40000);

        approveAllAccountsEntries();

        $this->getJson('/api/accounts/owner-loans')
            ->assertJsonPath('summary.total_capital', 680000)
            ->assertJsonPath('summary.loans_owed', 60000)
            ->assertJsonPath('summary.open_count', 1)
            ->assertJsonPath('summary.settled_count', 1)
            ->assertJsonPath('summary.owners.0.name', 'Chairman')
            ->assertJsonPath('summary.owners.0.capital', 500000)
            ->assertJsonPath('summary.owners.0.loan_owed', 60000)
            ->assertJsonPath('summary.owners.1.capital', 180000)
            ->assertJsonPath('data.0.owner.name', 'MD')
            ->assertJsonPath('data.0.status', 'settled')
            ->assertJsonPath('data.1.amount', 100000)
            ->assertJsonPath('data.1.repaid', 40000)
            ->assertJsonPath('data.1.still_owed', 60000)
            ->assertJsonPath('data.1.status', 'open');

        $this->getJson('/api/accounts/owner-loans?status=open')->assertJsonCount(1, 'data');
        $this->getJson("/api/accounts/owner-loans?party_id={$md->id}")->assertJsonCount(1, 'data');
    });

    it('needs a loan of this owner, approved, and no more than is still owed', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $owner = Party::factory()->owner()->create();
        $other = Party::factory()->owner()->create();
        ownerReceipt($owner, 500000, 'cash');
        $loan = ownerReceipt($owner, 10000);
        $othersLoan = ownerReceipt($other, 10000);

        repayment($owner, null, 100)->assertUnprocessable()->assertJsonValidationErrors(['loan_receipt_id' => 'Choose the loan being repaid.']);
        repayment($owner, $othersLoan, 100)->assertUnprocessable()->assertJsonValidationErrors(['loan_receipt_id' => 'Choose a loan of this owner.']);
        repayment($owner, $loan, 10001)->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Only ৳10,000.00 is still owed on loan CR-2026-0002 (counting repayments waiting for approval).']);

        // Two pending repayments may not add up to more than the loan.
        $first = repayment($owner, $loan, 6000)->assertCreated()->json('data.id');
        repayment($owner, $loan, 5000)->assertUnprocessable()->assertJsonValidationErrors('amount');
        // Editing a repayment does not count itself.
        $this->putJson("/api/accounts/cash-payments/{$first}", [
            'date' => '2026-10-05', 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'loan_repayment',
            'loan_receipt_id' => $loan, 'amount' => 10000, 'payment_method' => 'cash',
        ])->assertOk();

        // A loan waiting for approval cannot be repaid yet.
        $pending = $this->postJson('/api/accounts/cash-receipts', [
            'date' => '2026-10-03', 'party_type' => 'owner', 'party_id' => $owner->id,
            'receipt_type' => 'loan', 'amount' => 900, 'payment_method' => 'cash',
        ])->json('data.id');
        repayment($owner, $pending, 100)->assertUnprocessable()->assertJsonValidationErrors(['loan_receipt_id' => 'Loan CR-2026-0004 is not approved, so it cannot be repaid yet.']);
    });

    it('checks again at approval, and keeps a repaid loan from being returned', function () {
        $clerk = User::factory()->accounts()->create();
        $head = User::factory()->accountsHead()->create();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($clerk);
        $owner = Party::factory()->owner()->create();
        ownerReceipt($owner, 500000, 'cash');
        $loan = ownerReceipt($owner, 10000);

        $payment = repayment($owner, $loan, 4000)->assertCreated()->json('data.id');

        // Returning the loan is refused while a repayment names it.
        Sanctum::actingAs($admin);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$loan}/return", ['reason' => 'Wrong owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Loan CR-2026-0002 already has repayments. Return those payments first.']);

        // The loan was lowered behind the repayment's back: approval refuses it.
        DB::table('cash_receipts')->where('id', $loan)->update(['amount' => 3000]);
        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/cash-payments/{$payment}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Only ৳3,000.00 is still owed on loan CR-2026-0002.']);
        DB::table('cash_receipts')->where('id', $loan)->update(['amount' => 10000]);
        $this->postJson("/api/accounts/approvals/cash-payments/{$payment}/approve")->assertOk();

        expect(CashPayment::query()->findOrFail($payment)->loan_receipt_id)->toBe($loan);
    });

    it('is for the accounts team only', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/accounts/owner-loans')->assertForbidden();
    });
});
