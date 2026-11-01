<?php

use App\Models\CashPayment;
use App\Models\Party;
use App\Models\PartyTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Put cash in hand: an approved cash receipt from a fresh party. */
function receiveCash(float $amount, string $date = '2026-10-01'): void
{
    $party = Party::factory()->create();
    test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => 'client', 'party_id' => $party->id,
        'business_type' => 'recruitment', 'country_code' => 'MY',
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => 'cash',
    ])->assertCreated();
    approveAllAccountsEntries();
}

function paymentPayload(array $overrides = []): array
{
    return [
        'date' => '2026-10-10',
        'payee_type' => 'other',
        'payee_name' => '  Rahman   Properties ',
        'payment_type' => 'expense',
        'expense_category' => 'office_rent',
        'purpose' => 'Office rent',
        'amount' => 500,
        'payment_method' => 'cash',
        ...$overrides,
    ];
}

describe('cash payments', function () {
    it('pays another payee as an expense, numbered per year, with no ledger line', function () {
        Sanctum::actingAs($user = User::factory()->accounts()->create());
        receiveCash(5000);

        $this->postJson('/api/accounts/cash-payments', paymentPayload())
            ->assertCreated()
            ->assertJsonPath('data.payment_no', 'CP-2026-0001')
            ->assertJsonPath('data.payee_type', 'other')
            ->assertJsonPath('data.payee_name', 'Rahman Properties')
            ->assertJsonPath('data.party', null)
            ->assertJsonPath('data.purpose', 'Office rent')
            ->assertJsonPath('data.created_by', $user->name);

        expect(PartyTransaction::query()->whereNotNull('cash_payment_id')->count())->toBe(0);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['date' => '2027-01-05']))
            ->assertJsonPath('data.payment_no', 'CP-2027-0001');
    });

    it('writes a debit line in the payee ledger for a refund, loan repayment and drawing', function (string $partyType, string $paymentType) {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->state(['type' => $partyType])->create();

        $this->postJson('/api/accounts/cash-payments', paymentPayload([
            'payee_type' => $partyType, 'party_id' => $party->id, 'payment_type' => $paymentType,
            'payee_name' => 'Ignored', 'purpose' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.party.code', $party->code)
            ->assertJsonPath('data.payee_name', $party->name);

        expect($party->transactions()->sole())
            ->type->value->toBe($paymentType)
            ->amount->toBe('500.00');

        approveAllAccountsEntries();
        $this->getJson("/api/accounts/parties/{$party->id}")
            ->assertJsonPath("data.totals.{$paymentType}", 500)
            ->assertJsonPath('data.totals.closing_balance', 500);
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertJsonPath('lines.0.reference', 'CP-2026-0001')
            ->assertJsonPath('lines.0.is_manual', false)
            ->assertJsonPath('lines.0.debit', 500);
    })->with([
        'party refund' => ['client', 'refund'],
        'owner drawing' => ['owner', 'drawing'],
    ]);

    it('only allows the payment types of the payee', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $client = Party::factory()->create();
        $owner = Party::factory()->owner()->create();

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $client->id, 'payment_type' => 'drawing']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        // Only owners lend money, so a party gets no loan repayment.
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $client->id, 'payment_type' => 'loan_repayment']))
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_type' => 'A payment to this payee can only be a refund.']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        // A party of the wrong type, and a missing payee name and purpose.
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $owner->id, 'payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('party_id');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_name' => '', 'purpose' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['payee_name', 'purpose']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_method' => 'bank']))
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number']);
    });

    it('keeps an old party loan repayment editable, so it can be changed to a refund', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->create();
        // Saved as a party loan repayment before the rule.
        $id = $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'refund']))->json('data.id');
        $old = CashPayment::query()->findOrFail($id);
        $old->forceFill(['payment_type' => 'loan_repayment'])->save();

        $this->putJson("/api/accounts/cash-payments/{$old->id}", paymentPayload(['payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'loan_repayment', 'amount' => 300]))
            ->assertOk();
        $this->putJson("/api/accounts/cash-payments/{$old->id}", paymentPayload(['payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'refund']))
            ->assertOk()
            ->assertJsonPath('data.payment_type', 'refund');
    });

    it('pays by mobile banking to any payee, leaving cash in hand alone', function (string $payeeType, string $paymentType) {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = $payeeType === 'other' ? null : Party::factory()->state(['type' => $payeeType])->create();
        receiveCash(800);

        // Mobile banking does not need cash in hand: more than the 800 in the box is accepted.
        $this->postJson('/api/accounts/cash-payments', paymentPayload([
            'payee_type' => $payeeType, 'party_id' => $party?->id, 'payment_type' => $paymentType, 'amount' => 900,
            'payment_method' => 'mobile_banking', 'mobile_provider' => 'nagad', 'mobile_number' => '01812-345678',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_method_label', 'Mobile banking')
            ->assertJsonPath('data.mobile_provider_label', 'Nagad')
            ->assertJsonPath('data.mobile_number', '01812345678');

        approveAllAccountsEntries();
        $this->getJson('/api/accounts/cash-payments')
            ->assertJsonPath('meta.totals.cash', 0)
            ->assertJsonPath('meta.totals.mobile_banking', 900);
        financeSummary()
            ->assertJsonPath('data.cash_in_hand', 800)
            ->assertJsonPath('data.mobile_banking_balance', -900)
            ->assertJsonPath('data.bank_balance', 0);
        // Nor is it a line in the cash book.
        $this->getJson('/api/accounts/cash-book?from=2026-10-10&to=2026-10-10')->assertJsonPath('days', []);
    })->with([
        'party refund' => ['client', 'refund'],
        'owner drawing' => ['owner', 'drawing'],
        'other expense' => ['other', 'expense'],
    ]);

    it('requires Paid via, and the service and wallet number for mobile banking', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_method' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method' => 'Choose how the money was paid.']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_method' => 'mobile_banking', 'mobile_number' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_provider', 'mobile_number']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payee_name' => 'Enter the name of who you paid.']);
    });

    it('records the expense category and bill number for another payee only', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['expense_category' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expense_category' => 'Choose what the money was spent on.']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['expense_category' => 'lottery']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_category');

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['expense_category' => 'tea_entertainment', 'purpose' => 'Guest tea', 'bill_no' => '  INV  2231 ']))
            ->assertCreated()
            ->assertJsonPath('data.expense_category', 'tea_entertainment')
            ->assertJsonPath('data.expense_category_label', 'Tea & entertainment')
            ->assertJsonPath('data.bill_no', 'INV 2231');
        $this->getJson('/api/accounts/cash-payments?q=2231')->assertJsonPath('meta.total', 1);

        // A party or owner payment keeps neither.
        $owner = Party::factory()->owner()->create();
        $this->postJson('/api/accounts/cash-payments', paymentPayload([
            'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'drawing', 'bill_no' => 'X1',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_type_label', 'Payment to owner')
            ->assertJsonPath('data.expense_category', null)
            ->assertJsonPath('data.bill_no', null);
    });

    it('refuses a cash payment larger than the cash in hand, but not a bank or mobile banking one', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(300);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 500]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Not enough cash in hand: the balance would be −৳200.00 on 10-10-2026.']);

        expect(CashPayment::count())->toBe(0);

        // Paid from a bank or by mobile banking, it never comes out of cash in hand.
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 500, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567']))
            ->assertCreated()
            ->assertJsonPath('data.bank_name', 'DBBL');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 500, 'payment_method' => 'mobile_banking', 'mobile_provider' => 'bkash', 'mobile_number' => '01712345678']))
            ->assertCreated();
        approveAllAccountsEntries();
        financeSummary()->assertJsonPath('data.cash_in_hand', 300);
    });

    it('moves the ledger line when a payment is edited, and drops it when it becomes an expense', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->owner()->create();

        $id = $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'owner', 'party_id' => $party->id, 'payment_type' => 'drawing']))->json('data.id');
        $this->putJson("/api/accounts/cash-payments/{$id}", paymentPayload(['payee_type' => 'owner', 'party_id' => $party->id, 'payment_type' => 'drawing', 'amount' => 700, 'date' => '2026-10-12']))
            ->assertOk()
            ->assertJsonPath('data.payment_no', 'CP-2026-0001');
        expect($party->transactions()->sole())->amount->toBe('700.00')->date->format('Y-m-d')->toBe('2026-10-12');

        $this->putJson("/api/accounts/cash-payments/{$id}", paymentPayload())->assertOk()->assertJsonPath('data.party', null);
        expect($party->transactions()->count())->toBe(0);
    });

    it('deletes an approved payment only after an admin returns it, removing its ledger line', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->owner()->create();
        $id = $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'owner', 'party_id' => $party->id, 'payment_type' => 'drawing']))->json('data.id');
        approveAllAccountsEntries();

        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->deleteJson("/api/accounts/cash-payments/{$id}")->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/accounts/approvals/cash-payments/{$id}/return", ['reason' => 'Wrong owner'])->assertOk();

        // The accounts head deletes it.
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->deleteJson("/api/accounts/cash-payments/{$id}")->assertNoContent();
        expect($party->transactions()->count())->toBe(0);
    });

    it('filters the list and totals every matching payment', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $owner = Party::factory()->owner()->create(['name' => 'Chairman']);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 100]));
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 200, 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'drawing', 'purpose' => null]));
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 400, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567']));
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/cash-payments')
            ->assertOk()
            ->assertJsonPath('meta.totals', ['count' => 3, 'amount' => 700, 'cash' => 300, 'bank' => 400, 'mobile_banking' => 0]);
        $this->getJson('/api/accounts/cash-payments?q=chairman')->assertJsonPath('data.*.payment_type', ['drawing']);
        $this->getJson('/api/accounts/cash-payments?q=office rent')->assertJsonPath('meta.totals.count', 2);
        $this->getJson('/api/accounts/cash-payments?payee_type=other&payment_method=cash')->assertJsonPath('meta.totals.amount', 100);
    });
});
