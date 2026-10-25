<?php

use App\Models\CashReceipt;
use App\Models\Party;
use App\Models\PartyTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function receiptPayload(Party $party, array $overrides = []): array
{
    return [
        'date' => '2026-10-10',
        'party_type' => $party->type->value,
        'party_id' => $party->id,
        'receipt_type' => 'cash',
        'amount' => 2500,
        'payment_method' => 'cash',
        'bank_name' => 'Ignored Bank',
        'bank_branch' => 'Ignored',
        'note' => '  First   instalment ',
        ...$overrides,
    ];
}

describe('cash receipts', function () {
    it('records a receipt with the payer details from the party and a numbered receipt no', function () {
        $user = User::factory()->accounts()->create();
        Sanctum::actingAs($user);
        $party = Party::factory()->create(['name' => 'Rahim Uddin', 'mobile' => '01811111111', 'address' => 'Feni']);

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party))
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'CR-2026-0001')
            ->assertJsonPath('data.party.code', $party->code)
            ->assertJsonPath('data.party.name', 'Rahim Uddin')
            ->assertJsonPath('data.party.mobile', '01811111111')
            ->assertJsonPath('data.party.address', 'Feni')
            ->assertJsonPath('data.amount', 2500)
            ->assertJsonPath('data.note', 'First instalment')
            // Cash in hand: bank details are dropped.
            ->assertJsonPath('data.bank_name', null)
            ->assertJsonPath('data.created_by', $user->name);

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['payment_method' => 'bank', 'bank_name' => 'Sonali Bank', 'bank_branch' => 'Motijheel']))
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'CR-2026-0002')
            ->assertJsonPath('data.bank_name', 'Sonali Bank')
            ->assertJsonPath('data.bank_branch', 'Motijheel');

        // A new year starts a new series.
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['date' => '2027-01-02']))
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'CR-2027-0001');
    });

    it('writes the right ledger line for party and owner, cash and loan', function (string $partyType, string $receiptType, string $line) {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->state(['type' => $partyType])->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['receipt_type' => $receiptType]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // Pending: written, but not in the balance until approved.
        $this->getJson("/api/accounts/parties/{$party->id}")->assertJsonPath('data.totals.closing_balance', 0);
        approveAllAccountsEntries();

        expect($party->transactions()->sole())
            ->type->value->toBe($line)
            ->amount->toBe('2500.00');

        $this->getJson("/api/accounts/parties/{$party->id}")->assertJsonPath('data.totals.closing_balance', -2500);
    })->with([
        'party cash is a payment' => ['client', 'cash', 'payment'],
        'party loan is a loan' => ['client', 'loan', 'loan'],
        'owner cash is capital' => ['owner', 'cash', 'capital'],
        'owner loan is a loan' => ['owner', 'loan', 'loan'],
    ]);

    it('requires the bank name and branch for a bank deposit', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['payment_method' => 'bank', 'bank_name' => '', 'bank_branch' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_name', 'bank_branch']);
    });

    it('only takes an active party of the chosen type', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $owner = Party::factory()->owner()->create();
        $inactive = Party::factory()->inactive()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($owner, ['party_type' => 'client']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($inactive))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('party_id');
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($owner, ['amount' => 0, 'receipt_type' => 'gift']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'receipt_type']);
    });

    it('edits a receipt and moves its ledger line with it', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $first = Party::factory()->create();
        $second = Party::factory()->owner()->create();

        $id = $this->postJson('/api/accounts/cash-receipts', receiptPayload($first))->json('data.id');
        // The first party is deactivated later; the receipt can still be saved with it.
        $first->forceFill(['is_active' => false])->save();
        $this->putJson("/api/accounts/cash-receipts/{$id}", receiptPayload($first, ['amount' => 100]))->assertOk();

        $this->putJson("/api/accounts/cash-receipts/{$id}", receiptPayload($second, ['amount' => 900, 'receipt_type' => 'loan', 'date' => '2027-03-01']))
            ->assertOk()
            ->assertJsonPath('data.receipt_no', 'CR-2026-0001')
            ->assertJsonPath('data.party.id', $second->id)
            ->assertJsonPath('data.amount', 900);

        expect($first->transactions()->count())->toBe(0);
        expect($second->transactions()->sole())
            ->type->value->toBe('loan')
            ->amount->toBe('900.00')
            ->date->format('Y-m-d')->toBe('2027-03-01');
    });

    it('lets the preparer delete a pending receipt, with its ledger line, but nobody an approved one', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();
        $id = $this->postJson('/api/accounts/cash-receipts', receiptPayload($party))->json('data.id');

        // Another accounts user may not touch it; its preparer may.
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->deleteJson("/api/accounts/cash-receipts/{$id}")->assertForbidden();
        Sanctum::actingAs(CashReceipt::find($id)->creator);
        $this->deleteJson("/api/accounts/cash-receipts/{$id}")->assertNoContent();

        expect(CashReceipt::count())->toBe(0);
        expect(PartyTransaction::count())->toBe(0);

        $id = $this->postJson('/api/accounts/cash-receipts', receiptPayload($party))->json('data.id');
        approveAllAccountsEntries();
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->deleteJson("/api/accounts/cash-receipts/{$id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Approved entries are locked. Ask an admin to return it for correction before you delete it.');
    });

    it('filters the list and totals every matching receipt', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $client = Party::factory()->create(['name' => 'Karim Traders']);
        $owner = Party::factory()->owner()->create(['name' => 'Chairman']);

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($client, ['date' => '2026-10-01', 'amount' => 100]));
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($client, ['date' => '2026-10-02', 'amount' => 200, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara']));
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($owner, ['date' => '2026-10-03', 'amount' => 400, 'receipt_type' => 'loan']));

        // Pending receipts are listed but totalled apart.
        $this->getJson('/api/accounts/cash-receipts')
            ->assertJsonPath('meta.totals.amount', 0)
            ->assertJsonPath('meta.pending', ['count' => 3, 'amount' => 700]);
        approveAllAccountsEntries();

        $this->getJson('/api/accounts/cash-receipts?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.*.receipt_no', ['CR-2026-0003'])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.totals', ['count' => 3, 'amount' => 700, 'cash' => 500, 'bank' => 200])
            ->assertJsonPath('meta.pending', ['count' => 0, 'amount' => 0]);
        $this->getJson('/api/accounts/cash-receipts?status=pending')->assertJsonPath('meta.total', 0);

        $this->getJson('/api/accounts/cash-receipts?party_type=client&from=2026-10-02')
            ->assertJsonPath('data.*.receipt_no', ['CR-2026-0002']);
        $this->getJson('/api/accounts/cash-receipts?q=karim')
            ->assertJsonPath('meta.totals.amount', 300);
        $this->getJson('/api/accounts/cash-receipts?receipt_type=loan')
            ->assertJsonPath('data.*.party.name', ['Chairman']);
        $this->getJson("/api/accounts/cash-receipts?party_id={$client->id}&payment_method=cash")
            ->assertJsonPath('meta.totals.amount', 100);
    });
});
