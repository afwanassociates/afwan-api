<?php

use App\Models\Party;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** An approved cash payment. */
function expensePaid(array $data): int
{
    $id = test()->postJson('/api/accounts/cash-payments', [
        'date' => '2026-10-10', 'payment_method' => 'cash', ...$data,
    ])->assertCreated()->json('data.id');
    approveAllAccountsEntries();

    return $id;
}

describe('expense report', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        // Cash to spend from.
        $this->postJson('/api/accounts/cash-receipts', [
            'date' => '2026-10-01', 'party_type' => 'owner', 'party_id' => Party::factory()->owner()->create()->id,
            'receipt_type' => 'cash', 'amount' => 500000, 'payment_method' => 'cash',
        ])->assertCreated();
        approveAllAccountsEntries();

        $this->owner = Party::factory()->owner()->create(['name' => 'Asad Rahman']);
        $this->client = Party::factory()->create();
        expensePaid(['payee_type' => 'other', 'payee_name' => 'Rahman Properties', 'payment_type' => 'expense', 'expense_category' => 'office_rent', 'purpose' => 'October rent', 'bill_no' => 'R-10', 'amount' => 40000]);
        expensePaid(['date' => '2026-10-12', 'payee_type' => 'other', 'payee_name' => 'DESCO', 'payment_type' => 'expense', 'expense_category' => 'utility_bill', 'purpose' => 'Electricity', 'amount' => 8500]);
        // A refund and a loan are money paid, but not spending.
        expensePaid(['payee_type' => 'client', 'party_id' => $this->client->id, 'payment_type' => 'refund', 'amount' => 10000]);
        $this->loan = expensePaid(['payee_type' => 'owner', 'party_id' => $this->owner->id, 'payment_type' => 'owner_loan', 'amount' => 50000]);

        // The owner shows a 30,000 rent bill for the loan; it is spending (no money moves).
        $this->bill = $this->post("/api/accounts/owner-loans/{$this->loan}/bills", [
            'date' => '2026-10-15', 'expense_category' => 'office_rent', 'purpose' => 'Furniture shop rent', 'bill_no' => 'FR-1',
            'amount' => 30000, 'bill_copy' => fakeScan('pdf', 'rent.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    });

    it('lists approved expense payments and owner loan bills, with totals per category', function () {
        // The bill waits for approval: not spent yet.
        $this->getJson('/api/accounts/expenses')->assertJsonPath('meta.totals.amount', 48500);
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/expenses')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.source', 'owner_loan')
            ->assertJsonPath('data.0.source_label', 'Owner loan CP-2026-0004')
            ->assertJsonPath('data.0.paid_to', 'Asad Rahman')
            ->assertJsonPath('data.0.payment_method_label', 'Paid by the owner')
            ->assertJsonPath('data.0.has_copy', true)
            ->assertJsonPath('data.1.source_label', 'Paid directly')
            ->assertJsonPath('data.1.paid_to', 'DESCO')
            ->assertJsonPath('meta.totals.amount', 78500)
            ->assertJsonPath('meta.totals.direct', 48500)
            ->assertJsonPath('meta.totals.owner_loan', 30000)
            ->assertJsonPath('meta.totals.by_category.0', ['category' => 'office_rent', 'label' => 'Office rent', 'count' => 2, 'amount' => 70000])
            ->assertJsonPath('meta.totals.by_category.1.amount', 8500);
    });

    it('filters by date, category, source and search', function () {
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/expenses?category=office_rent')->assertJsonPath('meta.totals.amount', 70000);
        $this->getJson('/api/accounts/expenses?source=owner_loan')->assertJsonPath('data.*.purpose', ['Furniture shop rent']);
        $this->getJson('/api/accounts/expenses?from=2026-10-11&to=2026-10-14')->assertJsonPath('data.*.purpose', ['Electricity']);
        $this->getJson('/api/accounts/expenses?q=R-10')->assertJsonPath('data.*.purpose', ['October rent']);
        $this->getJson('/api/accounts/expenses?category=salary')->assertJsonPath('meta.totals', [
            'count' => 0, 'amount' => 0, 'direct' => 0, 'owner_loan' => 0, 'by_category' => [],
        ]);
        $this->getJson('/api/accounts/expenses?from=2026-10-20&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    });

    it('downloads the expenses as CSV', function () {
        approveAllAccountsEntries();

        $csv = $this->get('/api/accounts/expenses?export=csv&category=office_rent')->assertOk()->streamedContent();

        expect($csv)->toContain('Date,Category,Description,"Bill no.","Paid to",Source,"Paid via",Amount')
            ->toContain('15-10-2026,"Office rent","Furniture shop rent",FR-1,"Asad Rahman","Owner loan CP-2026-0004","Paid by the owner",30000.00')
            ->toContain('10-10-2026,"Office rent","October rent",R-10,"Rahman Properties","Paid directly","Cash in hand",40000.00')
            ->not->toContain('Electricity');
    });

    it('adds total expenses and what the money paid was for to the money summary', function () {
        approveAllAccountsEntries();

        financeSummary()
            ->assertJsonPath('data.total_paid', 108500)
            ->assertJsonPath('data.paid_breakdown', ['expenses' => 48500, 'refunds' => 10000, 'owner_loans' => 50000])
            ->assertJsonPath('data.total_expenses', 78500);
    });

    it('is for the accounts team only', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/accounts/expenses')->assertForbidden();
    });
});
