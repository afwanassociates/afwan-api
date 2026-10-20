<?php

use App\Models\Party;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function partyPayload(array $overrides = []): array
{
    return [
        'type' => 'client',
        'name' => '  Md   Karim  ',
        'mobile' => '01711-000000',
        'address' => 'Mirpur 10, Dhaka',
        'nid_passport' => ' ab 123 456 ',
        'tin_bin' => '',
        'opening_balance' => 1500.5,
        'opening_balance_type' => 'receivable',
        ...$overrides,
    ];
}

describe('parties', function () {
    it('lets accounts, admin and super admin create, list, show and edit parties', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson('/api/accounts/parties', partyPayload(['is_active' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $id = $this->postJson('/api/accounts/parties', partyPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'PT-0001')
            ->assertJsonPath('data.type', 'client')
            ->assertJsonPath('data.name', 'Md Karim')
            ->assertJsonPath('data.nid_passport', 'AB123456')
            ->assertJsonPath('data.tin_bin', null)
            ->assertJsonPath('data.opening_balance', 1500.5)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.totals.closing_balance', 1500.5)
            ->json('data.id');

        $this->getJson("/api/accounts/parties/{$id}")->assertOk()->assertJsonPath('data.code', 'PT-0001');

        $this->patchJson("/api/accounts/parties/{$id}", ['mobile' => '+60 12 345 6789', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.mobile', '+60 12 345 6789')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.code', 'PT-0001');

        $this->getJson('/api/accounts/parties?q=karim&is_active=0')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);
    })->with(['accounts', 'admin', 'superAdmin']);

    it('numbers clients and owners in separate series', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N1']))->assertJsonPath('data.code', 'PT-0001');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N2', 'type' => 'owner']))->assertJsonPath('data.code', 'OW-001');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N3']))->assertJsonPath('data.code', 'PT-0002');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N4', 'type' => 'owner']))->assertJsonPath('data.code', 'OW-002');

        $this->getJson('/api/accounts/parties?type=owner')
            ->assertOk()
            ->assertJsonPath('data.*.code', ['OW-002', 'OW-001']);
    });

    it('validates the form and keeps the type fixed', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        Party::factory()->create(['nid_passport' => 'AB123456']);

        $this->postJson('/api/accounts/parties', partyPayload(['mobile' => 'call me', 'opening_balance' => -5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nid_passport', 'mobile', 'opening_balance']);

        $this->postJson('/api/accounts/parties', ['type' => 'supplier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'name', 'mobile', 'address', 'nid_passport']);

        $party = Party::factory()->create();
        $this->patchJson("/api/accounts/parties/{$party->id}", ['type' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
        // Saving the same NID again is fine.
        $this->patchJson("/api/accounts/parties/{$party->id}", ['nid_passport' => $party->nid_passport])->assertOk();
    });

    it('deletes only parties without transactions, and only for admins', function () {
        $party = Party::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->deleteJson("/api/accounts/parties/{$party->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-01', 'type' => 'receivable', 'amount' => 10])->assertCreated();
        $this->deleteJson("/api/accounts/parties/{$party->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'party_has_transactions');

        $empty = Party::factory()->create();
        $this->deleteJson("/api/accounts/parties/{$empty->id}")->assertNoContent();
        expect(Party::find($empty->id))->toBeNull();
    });

    it('refuses the accounts endpoints to data entry and guests', function () {
        $party = Party::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/accounts/parties')->assertForbidden();
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")->assertForbidden();
        $this->postJson('/api/accounts/parties', partyPayload())->assertForbidden();
        $this->getJson('/api/accounts/cash-receipts')->assertForbidden();
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/accounts/parties')->assertUnauthorized();
    });
});

describe('party ledger', function () {
    it('shows receivable, payment, refund and the running and closing balance', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        $party = Party::factory()->create(['opening_balance' => 1000, 'opening_balance_type' => 'receivable']);

        $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-02', 'type' => 'receivable', 'amount' => 5000, 'note' => 'Visa processing'])->assertCreated();
        $this->postJson('/api/accounts/cash-receipts', [
            'date' => '2026-10-05', 'party_type' => 'client', 'party_id' => $party->id,
            'receipt_type' => 'cash', 'amount' => 3000, 'payment_method' => 'cash',
        ])->assertCreated();
        // A refund is a cash payment (here from the bank), never a hand-entered line.
        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-07', 'payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'refund',
            'amount' => 200, 'payment_method' => 'bank', 'bank_name' => 'Sonali', 'bank_branch' => 'Feni',
        ])->assertCreated();

        foreach (['payment', 'refund'] as $type) {
            $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-07', 'type' => $type, 'amount' => 1])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['type' => 'Only a receivable can be entered here; record payments as cash receipts and refunds as cash payments.']);
        }

        // Nothing counts before approval; the lines wait under "pending".
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertJsonPath('lines', [])
            ->assertJsonPath('pending.*.type', ['receivable', 'payment', 'refund'])
            ->assertJsonPath('closing_balance', 1000);
        approveAllAccountsEntries();

        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertOk()
            ->assertJsonPath('opening_balance', 1000)
            ->assertJsonPath('lines.*.type', ['receivable', 'payment', 'refund'])
            ->assertJsonPath('lines.*.balance', [6000, 3000, 3200])
            ->assertJsonPath('lines.1.reference', 'CR-2026-0001')
            ->assertJsonPath('lines.1.is_manual', false)
            ->assertJsonPath('totals', ['debit' => 5200, 'credit' => 3000])
            ->assertJsonPath('closing_balance', 3200)
            ->assertJsonPath('party.totals.receivable', 5000)
            ->assertJsonPath('party.totals.payment', 3000)
            ->assertJsonPath('party.totals.refund', 200)
            ->assertJsonPath('party.totals.closing_balance', 3200);

        // From a date: earlier lines are brought forward into the opening balance.
        $this->getJson("/api/accounts/parties/{$party->id}/ledger?from=2026-10-05&to=2026-10-05")
            ->assertOk()
            ->assertJsonPath('opening_balance', 6000)
            ->assertJsonPath('lines.*.type', ['payment'])
            ->assertJsonPath('closing_balance', 3000);

        $this->getJson("/api/accounts/parties/{$party->id}/ledger?from=2026-10-05&to=2026-10-01")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });

    it('starts a payable opening balance on the credit side', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create(['opening_balance' => 750, 'opening_balance_type' => 'payable']);

        $this->getJson("/api/accounts/parties/{$party->id}/ledger")
            ->assertOk()
            ->assertJsonPath('opening_balance', -750)
            ->assertJsonPath('closing_balance', -750);
    });

    it('edits hand-entered lines but not lines written by a receipt', function () {
        Sanctum::actingAs($preparer = User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $manual = $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-02', 'type' => 'receivable', 'amount' => 100])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can.approve', false)
            ->json('data.id');
        $this->patchJson("/api/accounts/party-transactions/{$manual}", ['date' => '2026-10-03', 'type' => 'receivable', 'amount' => 80])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-03')
            ->assertJsonPath('data.amount', 80);
        // Another accounts user may not change someone else's entry.
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->deleteJson("/api/accounts/party-transactions/{$manual}")->assertForbidden();
        Sanctum::actingAs($preparer);

        $this->postJson('/api/accounts/cash-receipts', [
            'date' => '2026-10-05', 'party_type' => 'client', 'party_id' => $party->id,
            'receipt_type' => 'cash', 'amount' => 50, 'payment_method' => 'cash',
        ])->assertCreated();
        $fromReceipt = $party->transactions()->whereNotNull('cash_receipt_id')->value('id');

        $this->patchJson("/api/accounts/party-transactions/{$fromReceipt}", ['date' => '2026-10-03', 'type' => 'receivable', 'amount' => 1])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/accounts/party-transactions/{$fromReceipt}")->assertForbidden();
        $this->postJson("/api/accounts/approvals/party-transactions/{$fromReceipt}/approve")->assertNotFound();
        $this->deleteJson("/api/accounts/party-transactions/{$manual}")->assertNoContent();
    });
});
