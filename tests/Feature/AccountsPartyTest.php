<?php

use App\Models\Party;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A client agency gets an RL number and a name made from its NID (unique like
 * the NID, and a client's name is unique within its kind); an owner has none.
 */
function partyPayload(array $overrides = []): array
{
    $isOwner = ($overrides['type'] ?? 'client') === 'owner';

    return [
        'type' => 'client',
        ...($isOwner
            ? ['designation' => 'director']
            : ['kind' => 'agency', 'rl_number' => 'RL-'.trim($overrides['nid_passport'] ?? 'AB123456'), 'agency_owner_name' => '  Abdul   Karim ']),
        'name' => isset($overrides['nid_passport']) ? 'Md Karim '.trim($overrides['nid_passport']) : '  Md   Karim  ',
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
    it('lets accounts and the accounts head create, list, show and edit parties', function (string $role) {
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
    })->with(['accounts', 'accountsHead']);

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
            ->assertJsonValidationErrors(['type', 'name', 'mobile']);
        $this->postJson('/api/accounts/parties', ['type' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'designation', 'mobile', 'address', 'nid_passport']);
        $this->postJson('/api/accounts/parties', ['type' => 'client', 'name' => 'X', 'mobile' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind' => 'Choose whether the party is an agency or a person.']);

        $party = Party::factory()->create();
        $this->patchJson("/api/accounts/parties/{$party->id}", ['type' => 'owner'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
        // Saving the same NID again is fine.
        $this->patchJson("/api/accounts/parties/{$party->id}", ['nid_passport' => $party->nid_passport])->assertOk();
    });

    it('requires a unique RL number for clients and refuses one for owners', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $id = $this->postJson('/api/accounts/parties', partyPayload(['rl_number' => '  rl  1234 ']))
            ->assertCreated()
            ->assertJsonPath('data.rl_number', 'RL 1234')
            ->json('data.id');

        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N2', 'rl_number' => 'RL 1234']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N3', 'rl_number' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
        $this->patchJson("/api/accounts/parties/{$id}", ['rl_number' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
        $this->patchJson("/api/accounts/parties/{$id}", ['rl_number' => 'RL 1234'])->assertOk();

        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N4', 'type' => 'owner', 'rl_number' => 'RL 9']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
        $ownerId = $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N6', 'type' => 'owner']))
            ->assertCreated()
            ->assertJsonPath('data.rl_number', null)
            ->json('data.id');
        $this->patchJson("/api/accounts/parties/{$ownerId}", ['rl_number' => 'RL 9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');

        $this->getJson('/api/accounts/parties?q=rl 12')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);
    });

    it('requires a designation for new owners and refuses one for clients', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N1', 'type' => 'owner', 'designation' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('designation');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N1', 'type' => 'owner', 'designation' => 'ceo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('designation');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N2', 'designation' => 'director']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('designation');

        $id = $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N3', 'type' => 'owner', 'designation' => 'managing_director']))
            ->assertCreated()
            ->assertJsonPath('data.designation', 'managing_director')
            ->assertJsonPath('data.designation_label', 'Managing Director')
            ->json('data.id');

        $this->patchJson("/api/accounts/parties/{$id}", ['designation' => 'chairman'])
            ->assertOk()
            ->assertJsonPath('data.designation_label', 'Chairman');
        $this->patchJson("/api/accounts/parties/{$id}", ['designation' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('designation');
    });

    it('keeps the agency owner name, address and NID optional for an agency and refuses an agency owner for others', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $id = $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N1']))
            ->assertCreated()
            ->assertJsonPath('data.agency_owner_name', 'Abdul Karim')
            ->json('data.id');
        $this->postJson('/api/accounts/parties', partyPayload(['agency_owner_name' => ' ', 'nid_passport' => '', 'address' => '', 'name' => 'Short Agency', 'rl_number' => 'RL-SHORT']))
            ->assertCreated()
            ->assertJsonPath('data.agency_owner_name', null)
            ->assertJsonPath('data.nid_passport', null)
            ->assertJsonPath('data.address', null);
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N3', 'type' => 'owner', 'agency_owner_name' => 'X']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agency_owner_name');
        $this->postJson('/api/accounts/parties', partyPayload(['nid_passport' => 'N4', 'kind' => 'person', 'rl_number' => null, 'agency_owner_name' => 'X']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['agency_owner_name' => 'Only agencies have an agency owner.']);

        $this->patchJson("/api/accounts/parties/{$id}", ['agency_owner_name' => 'Rahim Uddin'])
            ->assertOk()
            ->assertJsonPath('data.agency_owner_name', 'Rahim Uddin');
        $this->getJson('/api/accounts/parties?q=rahim')->assertJsonPath('data.*.id', [$id]);
    });

    it('takes a person client with only a name and a mobile, without an RL number', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $id = $this->postJson('/api/accounts/parties', ['type' => 'client', 'kind' => 'person', 'name' => 'Abdul Karim', 'mobile' => '01711000000'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'person')
            ->assertJsonPath('data.kind_label', 'Person')
            ->assertJsonPath('data.rl_number', null)
            ->json('data.id');

        $this->postJson('/api/accounts/parties', ['type' => 'client', 'kind' => 'person', 'name' => 'Rahim', 'mobile' => '01711000000', 'rl_number' => 'RL-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rl_number' => 'Only agencies have an RL number.']);
        $this->postJson('/api/accounts/parties', ['type' => 'client', 'kind' => 'person', 'name' => 'Rahim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile' => 'Enter the mobile number.']);
        $this->postJson('/api/accounts/parties', ['type' => 'client', 'kind' => 'person', 'name' => 'abdul  KARIM', 'mobile' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A person with this name already exists.']);

        // Turning the person into an agency needs an RL number.
        $this->patchJson("/api/accounts/parties/{$id}", ['kind' => 'agency'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
        $this->patchJson("/api/accounts/parties/{$id}", ['kind' => 'agency', 'rl_number' => 'RL-55'])
            ->assertOk()
            ->assertJsonPath('data.kind', 'agency');
    });

    it('never deletes a party or owner, for anyone', function (string $role) {
        $party = Party::factory()->create();
        $owner = Party::factory()->owner()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->deleteJson("/api/accounts/parties/{$party->id}")->assertMethodNotAllowed();
        $this->deleteJson("/api/accounts/parties/{$owner->id}")->assertMethodNotAllowed();
        expect(Party::count())->toBe(2);
    })->with(['accounts', 'accountsHead', 'admin', 'superAdmin']);

    it('keeps parties and cash from admins: they only approve', function (string $role) {
        $party = Party::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/accounts/parties')->assertForbidden();
        $this->getJson("/api/accounts/parties/{$party->id}/ledger")->assertForbidden();
        $this->getJson('/api/accounts/cash-receipts')->assertForbidden();
        $this->getJson('/api/accounts/cash-book')->assertForbidden();
        $this->putJson('/api/accounts/cash-settings', ['opening_balance' => 1, 'opening_date' => '2026-10-01'])->assertMethodNotAllowed();
        $this->getJson('/api/accounts/approvals')->assertOk();
        $this->getJson('/api/accounts/approvals/summary')->assertOk()->assertJsonPath('data.is_approver', true);
    })->with(['admin', 'superAdmin']);

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
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $party = Party::factory()->create(['opening_balance' => 1000, 'opening_balance_type' => 'receivable']);

        $this->postJson("/api/accounts/parties/{$party->id}/transactions", ['date' => '2026-10-02', 'type' => 'receivable', 'amount' => 5000, 'note' => 'Visa processing'])->assertCreated();
        $this->postJson('/api/accounts/cash-receipts', [
            'date' => '2026-10-05', 'party_type' => 'client', 'party_id' => $party->id,
            'business_type' => 'recruitment', 'country_code' => 'MY',
            'receipt_type' => 'cash', 'amount' => 3000, 'payment_method' => 'cash',
        ])->assertCreated();
        // A refund is a cash payment (here from the bank), never a hand-entered line.
        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-10-07', 'payee_type' => 'client', 'party_id' => $party->id, 'payment_type' => 'refund',
            'amount' => 200, 'payment_method' => 'bank', 'bank_name' => 'Sonali', 'bank_branch' => 'Feni', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567',
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
            'business_type' => 'recruitment', 'country_code' => 'MY',
            'receipt_type' => 'cash', 'amount' => 50, 'payment_method' => 'cash',
        ])->assertCreated();
        $fromReceipt = $party->transactions()->whereNotNull('cash_receipt_id')->value('id');

        $this->patchJson("/api/accounts/party-transactions/{$fromReceipt}", ['date' => '2026-10-03', 'type' => 'receivable', 'amount' => 1])->assertForbidden();

        // An approver (the accounts head) may delete someone else's pending line.
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->deleteJson("/api/accounts/party-transactions/{$fromReceipt}")->assertForbidden();
        $this->postJson("/api/accounts/approvals/party-transactions/{$fromReceipt}/approve")->assertNotFound();
        $this->deleteJson("/api/accounts/party-transactions/{$manual}")->assertNoContent();
    });
});
