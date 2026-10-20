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
        'party loan repayment' => ['client', 'loan_repayment'],
        'owner drawing' => ['owner', 'drawing'],
        'owner loan repayment' => ['owner', 'loan_repayment'],
    ]);

    it('only allows the payment types of the payee', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $client = Party::factory()->create();
        $owner = Party::factory()->owner()->create();

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $client->id, 'payment_type' => 'drawing']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        // A party of the wrong type, and a missing payee name and purpose.
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $owner->id, 'payment_type' => 'refund']))
            ->assertUnprocessable()->assertJsonValidationErrors('party_id');
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_name' => '', 'purpose' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['payee_name', 'purpose']);
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['payment_method' => 'bank']))
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_name', 'bank_branch']);
    });

    it('refuses a cash payment larger than the cash in hand', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(300);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 500]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Not enough cash in hand: the balance would be −৳200.00 on 10-10-2026.']);

        expect(CashPayment::count())->toBe(0);

        // Paid from a bank, it does not touch cash in hand.
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 500, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara']))
            ->assertCreated()
            ->assertJsonPath('data.bank_name', 'DBBL');
    });

    it('moves the ledger line when a payment is edited, and drops it when it becomes an expense', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->create();

        $id = $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'refund']))->json('data.id');
        $this->putJson("/api/accounts/cash-payments/{$id}", paymentPayload(['payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'loan_repayment', 'amount' => 700]))
            ->assertOk()
            ->assertJsonPath('data.payment_no', 'CP-2026-0001');
        expect($party->transactions()->sole())->type->value->toBe('loan_repayment')->amount->toBe('700.00');

        $this->putJson("/api/accounts/cash-payments/{$id}", paymentPayload())->assertOk()->assertJsonPath('data.party', null);
        expect($party->transactions()->count())->toBe(0);
    });

    it('deletes an approved payment only after an admin returns it, removing its ledger line', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $party = Party::factory()->owner()->create();
        $id = $this->postJson('/api/accounts/cash-payments', paymentPayload(['payee_type' => 'owner', 'party_id' => $party->id, 'payment_type' => 'drawing']))->json('data.id');
        approveAllAccountsEntries();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/accounts/cash-payments/{$id}")->assertForbidden();
        $this->postJson("/api/accounts/approvals/cash-payments/{$id}/return", ['reason' => 'Wrong owner'])->assertOk();
        $this->deleteJson("/api/accounts/cash-payments/{$id}")->assertNoContent();
        expect($party->transactions()->count())->toBe(0);
        // The party now has only a receipt-free history and can be deleted.
        $this->deleteJson("/api/accounts/parties/{$party->id}")->assertNoContent();
    });

    it('filters the list and totals every matching payment', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        receiveCash(5000);
        $owner = Party::factory()->owner()->create(['name' => 'Chairman']);

        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 100]));
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 200, 'payee_type' => 'owner', 'party_id' => $owner->id, 'payment_type' => 'drawing', 'purpose' => null]));
        $this->postJson('/api/accounts/cash-payments', paymentPayload(['amount' => 400, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara']));
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/cash-payments')
            ->assertOk()
            ->assertJsonPath('meta.totals', ['count' => 3, 'amount' => 700, 'cash' => 300, 'bank' => 400]);
        $this->getJson('/api/accounts/cash-payments?q=chairman')->assertJsonPath('data.*.payment_type', ['drawing']);
        $this->getJson('/api/accounts/cash-payments?q=office rent')->assertJsonPath('meta.totals.count', 2);
        $this->getJson('/api/accounts/cash-payments?payee_type=other&payment_method=cash')->assertJsonPath('meta.totals.amount', 100);
    });
});
