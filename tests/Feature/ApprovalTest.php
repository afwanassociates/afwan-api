<?php

use App\Models\CashPayment;
use App\Models\Party;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

function cashIn(Party $party, float $amount = 1000, string $date = '2026-10-05'): int
{
    return test()->postJson('/api/accounts/cash-receipts', [
        'date' => $date, 'party_type' => 'client', 'party_id' => $party->id,
        'receipt_type' => 'cash', 'amount' => $amount, 'payment_method' => 'cash',
    ])->assertCreated()->json('data.id');
}

function cashOut(float $amount, string $date = '2026-10-06'): TestResponse
{
    return test()->postJson('/api/accounts/cash-payments', [
        'date' => $date, 'payee_type' => 'other', 'payee_name' => 'Landlord', 'payment_type' => 'expense',
        'purpose' => 'Rent', 'amount' => $amount, 'payment_method' => 'cash',
    ]);
}

describe('approval', function () {
    it('keeps a receipt out of the books until the accounts head approves it', function () {
        $clerk = User::factory()->accounts()->create(['name' => 'Rina']);
        $head = User::factory()->accountsHead()->create(['name' => 'Head']);
        $party = Party::factory()->create();

        Sanctum::actingAs($clerk);
        $id = cashIn($party, 1000);
        $this->getJson("/api/accounts/cash-receipts/{$id}")
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Pending approval')
            ->assertJsonPath('data.prepared_by', 'Rina')
            ->assertJsonPath('data.can', ['update' => true, 'delete' => true, 'approve' => false, 'send_back' => false]);
        $this->getJson('/api/accounts/cash-settings')
            ->assertJsonPath('data.cash_in_hand', 0)
            ->assertJsonPath('data.pending', ['count' => 1, 'in' => 1000, 'out' => 0]);

        // The accounts team cannot approve.
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the accounts & finance head or an admin can approve entries.');

        Sanctum::actingAs($head);
        $this->getJson('/api/accounts/approvals/summary')->assertJsonPath('data', ['pending' => 1, 'returned_mine' => 0, 'is_approver' => true]);
        $this->getJson('/api/accounts/approvals')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'cash-receipts')
            ->assertJsonPath('data.0.reference', 'CR-2026-0001')
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.prepared_by', 'Rina')
            ->assertJsonPath('data.0.can', ['approve' => true, 'send_back' => true]);

        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->getJson("/api/accounts/cash-receipts/{$id}")
            ->assertJsonPath('data.approved_by', 'Head')
            ->assertJsonPath('data.can', ['update' => false, 'delete' => false, 'approve' => false, 'send_back' => false]);
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.cash_in_hand', 1000);
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertJsonPath('lines.0.reference', 'CR-2026-0001')
            ->assertJsonPath('pending', []);
        $this->getJson('/api/accounts/approvals/summary')->assertJsonPath('data.pending', 0);

        // Approved: locked, even for the preparer.
        Sanctum::actingAs($clerk);
        $this->deleteJson("/api/accounts/cash-receipts/{$id}")->assertForbidden();
    });

    it('never lets anyone approve an entry they prepared or edited, not even the accounts head', function () {
        $head = User::factory()->accountsHead()->create();
        $otherHead = User::factory()->accountsHead()->create();
        $party = Party::factory()->create();

        Sanctum::actingAs($head);
        $id = cashIn($party);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You prepared or edited this entry, so someone else must approve it.');

        // Another head edits it, so now only someone else (an admin) may approve.
        Sanctum::actingAs($otherHead);
        $this->putJson("/api/accounts/cash-receipts/{$id}", [
            'date' => '2026-10-05', 'party_type' => 'client', 'party_id' => $party->id,
            'receipt_type' => 'cash', 'amount' => 900, 'payment_method' => 'cash',
        ])->assertOk();
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")->assertForbidden();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")->assertOk();
    });

    it('returns an entry with a reason; the preparer fixes it and it is pending again', function () {
        $clerk = User::factory()->accounts()->create();
        $head = User::factory()->accountsHead()->create(['name' => 'Head']);
        $party = Party::factory()->create();

        Sanctum::actingAs($clerk);
        $id = cashIn($party, 1000);

        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/return", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'Write why it is returned, so the preparer can fix it.']);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/return", ['reason' => 'Amount does not match the slip'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->getJson('/api/accounts/approvals?status=rejected')
            ->assertJsonPath('data.0.return_reason', 'Amount does not match the slip')
            ->assertJsonPath('data.0.returned_by', 'Head');
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")->assertForbidden();

        Sanctum::actingAs($clerk);
        $this->getJson("/api/accounts/cash-receipts/{$id}")
            ->assertJsonPath('data.status_label', 'Returned')
            ->assertJsonPath('data.can.update', true);
        $this->putJson("/api/accounts/cash-receipts/{$id}", [
            'date' => '2026-10-05', 'party_type' => 'client', 'party_id' => $party->id,
            'receipt_type' => 'cash', 'amount' => 950, 'payment_method' => 'cash',
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")->assertOk();
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.cash_in_hand', 950);
    });

    it('lets only an admin return an approved entry for correction, which takes it out of the books', function () {
        $party = Party::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());
        $id = cashIn($party, 1000);
        Sanctum::actingAs($head = User::factory()->accountsHead()->create());
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/approve")->assertOk();

        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/return", ['reason' => 'Typo'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only an admin can return an approved entry for correction.');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/accounts/approvals/cash-receipts/{$id}/return", ['reason' => 'Typo'])->assertOk();

        Sanctum::actingAs($head);
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.cash_in_hand', 0);
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertJsonPath('lines', [])
            ->assertJsonPath('pending.0.status', 'rejected')
            ->assertJsonPath('pending.0.return_reason', 'Typo');
    });

    it('checks cash in hand again when approving a payment', function () {
        $party = Party::factory()->create();
        Sanctum::actingAs($clerk = User::factory()->accounts()->create());
        $receipt = cashIn($party, 1000);
        Sanctum::actingAs($head = User::factory()->accountsHead()->create());
        $this->postJson("/api/accounts/approvals/cash-receipts/{$receipt}/approve")->assertOk();

        // Two pending payments: each fits alone, not both.
        Sanctum::actingAs($clerk);
        $first = cashOut(700)->assertCreated()->json('data.id');
        $second = cashOut(700)->assertCreated()->json('data.id');
        cashOut(1500)->assertUnprocessable()->assertJsonValidationErrors('amount');

        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/cash-payments/{$first}/approve")->assertOk();
        $this->postJson("/api/accounts/approvals/cash-payments/{$second}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Not enough cash in hand: the balance would be −৳400.00 on 06-10-2026.']);

        expect(CashPayment::find($second)->status->value)->toBe('pending');
        $this->getJson('/api/accounts/cash-settings')->assertJsonPath('data.cash_in_hand', 300);
    });

    it('approves hand-entered ledger lines in the same queue', function () {
        $party = Party::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());
        $line = $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-02', 'type' => 'receivable', 'amount' => 5000])->json('data.id');

        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->getJson('/api/accounts/approvals?type=party-transactions')
            ->assertJsonPath('data.0.kind', 'Ledger entry')
            ->assertJsonPath('data.0.title', "Receivable · {$party->name} ({$party->code})");
        $this->postJson("/api/accounts/approvals/party-transactions/{$line}/approve")->assertOk();

        $this->getJson("/api/accounts/parties/{$party->id}")->assertJsonPath('data.totals.receivable', 5000);
    });

    it('shows the accounts team only the entries they prepared', function () {
        $party = Party::factory()->create();
        $rina = User::factory()->accounts()->create();
        $karim = User::factory()->accounts()->create();
        $head = User::factory()->accountsHead()->create();

        Sanctum::actingAs($rina);
        $mine = cashIn($party, 100);
        $mineToo = cashIn($party, 200);
        Sanctum::actingAs($karim);
        $theirs = cashIn($party, 300);

        Sanctum::actingAs($head);
        $this->postJson("/api/accounts/approvals/cash-receipts/{$mine}/return", ['reason' => 'Wrong date'])->assertOk();
        $this->postJson("/api/accounts/approvals/cash-receipts/{$theirs}/return", ['reason' => 'Wrong party'])->assertOk();
        // The head sees everyone's.
        $this->getJson('/api/accounts/approvals?status=rejected')->assertJsonPath('data.*.id', [$mine, $theirs]);

        Sanctum::actingAs($rina);
        $this->getJson('/api/accounts/approvals?status=rejected')
            ->assertJsonPath('data.*.id', [$mine])
            ->assertJsonPath('data.0.return_reason', 'Wrong date');
        $this->getJson('/api/accounts/approvals')->assertJsonPath('data.*.id', [$mineToo]);
        $this->getJson('/api/accounts/approvals/summary')
            ->assertJsonPath('data', ['pending' => 1, 'returned_mine' => 1, 'is_approver' => false]);
    });
});
