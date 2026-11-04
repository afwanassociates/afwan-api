<?php

use App\Models\OwnerLoanBill;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Cash in hand to lend from: a party's cash receipt, approved. */
function loanFund(float $amount, string $date = '2026-10-01'): void
{
    test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => 'client', 'party_id' => Party::factory()->create()->id,
        'business_type' => 'recruitment', 'country_code' => 'MY',
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => 'cash',
    ])->assertCreated();
    approveAllAccountsEntries();
}

/** Give an owner a loan from the company (a cash payment), approved. */
function giveOwnerLoan(Party $owner, float $amount, array $overrides = []): int
{
    $id = test()->postJson('/api/accounts/cash-payments', [
        'date' => '2026-10-05', 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'owner_loan',
        'amount' => $amount, 'payment_method' => 'cash', 'purpose' => 'Office setup', ...$overrides,
    ])->assertCreated()->json('data.id');
    approveAllAccountsEntries();

    return $id;
}

/** The owner returns money on a loan (a cash receipt of type loan_return). */
function returnOwnerLoan(Party $owner, ?int $loanId, float $amount, array $overrides = []): TestResponse
{
    return test()->postJson('/api/accounts/cash-receipts', [
        'date' => '2026-10-20', 'party_type' => 'owner', 'party_id' => $owner->id, 'receipt_type' => 'loan_return',
        'owner_loan_id' => $loanId, 'amount' => $amount, 'payment_method' => 'cash', ...$overrides,
    ]);
}

/** The owner shows a company bill paid with the loan, with a copy. */
function loanBill(int $loanId, float $amount, array $overrides = []): TestResponse
{
    return test()->post("/api/accounts/owner-loans/{$loanId}/bills", [
        'date' => '2026-10-15', 'expense_category' => 'office_rent', 'purpose' => '  October   office rent ',
        'bill_no' => 'R-10', 'amount' => $amount, 'bill_copy' => fakeScan('pdf', 'rent.pdf'), ...$overrides,
    ], ['Accept' => 'application/json']);
}

describe('owner loans', function () {
    it('lends an owner money from the company and settles it with a bill and returned cash', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        loanFund(100000, '2026-10-01');
        $owner = Party::factory()->owner()->create(['name' => 'Chairman']);

        // 50,000 out of cash in hand.
        $loan = giveOwnerLoan($owner, 50000);
        financeSummary()->assertJsonPath('data.cash_in_hand', 50000);
        expect($owner->transactions()->sole())->type->value->toBe('owner_loan')->amount->toBe('50000.00');
        $this->getJson('/api/accounts/owner-loans')
            ->assertJsonPath('summary.loans_outstanding', 50000)
            ->assertJsonPath('data.0.payment_no', 'CP-2026-0001')
            ->assertJsonPath('data.0.payment_method_label', 'Cash in hand')
            ->assertJsonPath('data.0.outstanding', 50000)
            ->assertJsonPath('data.0.status', 'open');

        // A 30,000 bill the owner paid: settles, no money moves.
        $billId = loanBill($loan, 30000)
            ->assertCreated()
            ->assertJsonPath('data.purpose', 'October office rent')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.has_copy', true)
            ->assertJsonPath('data.copy_name', 'rent.pdf')
            ->json('data.id');
        Storage::disk('local')->assertExists(OwnerLoanBill::findOrFail($billId)->copy_path);

        // 20,000 cash returned: back into cash in hand.
        returnOwnerLoan($owner, $loan, 20000)
            ->assertCreated()
            ->assertJsonPath('data.receipt_type_label', 'Loan return')
            ->assertJsonPath('data.owner_loan.payment_no', 'CP-2026-0001');

        // Waiting for approval: not settled yet.
        $this->getJson('/api/accounts/owner-loans')
            ->assertJsonPath('data.0.pending_billed', 30000)
            ->assertJsonPath('data.0.pending_returned', 20000)
            ->assertJsonPath('data.0.outstanding', 50000);

        approveAllAccountsEntries();

        $this->getJson('/api/accounts/owner-loans')
            ->assertJsonPath('summary.loans_outstanding', 0)
            ->assertJsonPath('summary.settled_count', 1)
            ->assertJsonPath('data.0.billed', 30000)
            ->assertJsonPath('data.0.returned', 20000)
            ->assertJsonPath('data.0.outstanding', 0)
            ->assertJsonPath('data.0.status', 'settled')
            ->assertJsonPath('data.0.bills.0.expense_category_label', 'Office rent');
        financeSummary()->assertJsonPath('data.cash_in_hand', 70000);
        $this->getJson("/api/accounts/parties/{$owner->id}")
            ->assertJsonPath('data.totals.owner_loan', 50000)
            ->assertJsonPath('data.totals.loan_bill', 30000)
            ->assertJsonPath('data.totals.loan_return', 20000)
            ->assertJsonPath('data.totals.closing_balance', 0);
    });

    it('lends by bank or mobile banking too, out of that balance', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $owner = Party::factory()->owner()->create();

        giveOwnerLoan($owner, 5000, ['payment_method' => 'mobile_banking', 'mobile_provider' => 'bkash', 'mobile_number' => '01712345678']);
        $bankLoan = giveOwnerLoan($owner, 8000, ['payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'bank_account_name' => 'Afwan', 'bank_account_number' => '123']);

        financeSummary()
            ->assertJsonPath('data.cash_in_hand', 0)
            ->assertJsonPath('data.mobile_banking_balance', -5000)
            ->assertJsonPath('data.bank_balance', -8000);

        // The owner returns 8,000 into the bank.
        returnOwnerLoan($owner, $bankLoan, 8000, ['payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'bank_account_name' => 'Afwan', 'bank_account_number' => '123'])
            ->assertCreated();
        approveAllAccountsEntries();
        financeSummary()->assertJsonPath('data.bank_balance', 0);
        $this->getJson('/api/accounts/owner-loans?status=open')->assertJsonCount(1, 'data');
    });

    it('never settles more than is outstanding, counting what waits for approval', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        loanFund(100000);
        $owner = Party::factory()->owner()->create();
        $other = Party::factory()->owner()->create();
        $loan = giveOwnerLoan($owner, 10000);
        $othersLoan = giveOwnerLoan($other, 10000);

        returnOwnerLoan($owner, $othersLoan, 100)->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_loan_id' => 'Choose a loan given to this owner.']);
        returnOwnerLoan($owner, $loan, 10001)->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Only ৳10,000.00 is outstanding on loan CP-2026-0001 (counting returns and bills waiting for approval).']);

        loanBill($loan, 6000)->assertCreated();
        returnOwnerLoan($owner, $loan, 5000)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $receipt = returnOwnerLoan($owner, $loan, 4000)->assertCreated()->json('data.id');
        loanBill($loan, 1)->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Loan CP-2026-0001 has nothing left to settle (counting returns and bills waiting for approval).']);
        // Editing a return does not count itself.
        $this->putJson("/api/accounts/cash-receipts/{$receipt}", [
            'date' => '2026-10-20', 'party_type' => 'owner', 'party_id' => $owner->id, 'receipt_type' => 'loan_return',
            'owner_loan_id' => $loan, 'amount' => 4000, 'payment_method' => 'cash',
        ])->assertOk();

        // A loan waiting for approval cannot be settled yet.
        $pending = $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-06', 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'owner_loan',
            'amount' => 500, 'payment_method' => 'cash',
        ])->json('data.id');
        loanBill($pending, 100)->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_loan_id' => 'Loan CP-2026-0003 is not approved yet, so it cannot be settled.']);
    });

    it('needs a bill copy, checks the bill, and replaces the copy on edit', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        loanFund(100000);
        $loan = giveOwnerLoan(Party::factory()->owner()->create(), 10000);

        loanBill($loan, 100, ['bill_copy' => null])->assertUnprocessable()
            ->assertJsonValidationErrors(['bill_copy' => 'Attach a copy of the bill (photo or PDF).']);
        loanBill($loan, 100, ['bill_copy' => fakeScan('pdf', 'bill.jpg')])->assertUnprocessable()
            ->assertJsonValidationErrors(['bill_copy' => 'The bill copy must be a real JPG, PNG or PDF file.']);
        loanBill($loan, 0, ['expense_category' => 'nope', 'purpose' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'expense_category', 'purpose']);

        $id = loanBill($loan, 3000)->json('data.id');
        $oldPath = OwnerLoanBill::findOrFail($id)->copy_path;

        // Edit without a new copy keeps it; with one replaces it.
        $this->post("/api/accounts/owner-loan-bills/{$id}", [
            'date' => '2026-10-16', 'expense_category' => 'utility_bill', 'purpose' => 'Electricity', 'amount' => 2500,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.amount', 2500)->assertJsonPath('data.has_copy', true);
        Storage::disk('local')->assertExists($oldPath);
        $this->post("/api/accounts/owner-loan-bills/{$id}", [
            'date' => '2026-10-16', 'expense_category' => 'utility_bill', 'purpose' => 'Electricity', 'amount' => 2500,
            'bill_copy' => fakeScan('png', 'new.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.copy_name', 'new.png');
        Storage::disk('local')->assertMissing($oldPath);

        $this->get("/api/accounts/owner-loan-bills/{$id}/copy")->assertOk()->assertHeader('Content-Type', 'image/png');

        // Deleting the bill removes its copy and its ledger line.
        $path = OwnerLoanBill::findOrFail($id)->copy_path;
        $this->deleteJson("/api/accounts/owner-loan-bills/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        expect(OwnerLoanBill::count())->toBe(0);
    });

    it('goes through approval: the queue shows bills, approval checks again, a settled loan is locked', function () {
        $clerk = User::factory()->accounts()->create();
        $head = User::factory()->accountsHead()->create();
        Sanctum::actingAs($clerk);
        loanFund(100000);
        $owner = Party::factory()->owner()->create(['name' => 'MD']);
        $loan = giveOwnerLoan($owner, 10000);
        $bill = loanBill($loan, 4000)->json('data.id');

        Sanctum::actingAs($head);
        // The bill waits once: its ledger line is not a separate entry, nor in the count.
        $this->getJson('/api/accounts/approvals')->assertJsonPath('data.*.kind', ['Owner loan bill']);
        $this->getJson('/api/accounts/approvals/summary')->assertJsonPath('data.pending', 1);
        $this->getJson('/api/accounts/approvals?type=owner-loan-bills')
            ->assertJsonPath('data.0.kind', 'Owner loan bill')
            ->assertJsonPath('data.0.reference', 'Bill R-10')
            ->assertJsonPath('data.0.amount', 4000)
            ->assertJsonPath('data.0.owner_loan', true)
            ->assertJsonPath('data.0.has_copy', true);
        // Approvers (admins too) may open the copy.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->get("/api/accounts/owner-loan-bills/{$bill}/copy")->assertOk();

        // The loan was lowered behind the bill's back: approval refuses it.
        DB::table('cash_payments')->where('id', $loan)->update(['amount' => 3000]);
        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/owner-loan-bills/{$bill}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Only ৳3,000.00 is outstanding on loan CP-2026-0001.']);
        DB::table('cash_payments')->where('id', $loan)->update(['amount' => 10000]);
        $this->postJson("/api/accounts/approvals/owner-loan-bills/{$bill}/approve")->assertOk();
        expect($owner->transactions()->where('type', 'loan_bill')->sole()->status->value)->toBe('approved');

        // The loan cannot be returned for correction while a bill settles it.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/accounts/approvals/cash-payments/{$loan}/return", ['reason' => 'Wrong owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Loan CP-2026-0001 already has returns or bills. Return or delete those first.']);

        // An approved bill is locked.
        Sanctum::actingAs($clerk);
        $this->deleteJson("/api/accounts/owner-loan-bills/{$bill}")->assertForbidden();
    });

    it('is for the accounts team only', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/accounts/owner-loans')->assertForbidden();
    });
});
