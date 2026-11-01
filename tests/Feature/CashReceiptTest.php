<?php

use App\Models\CashReceipt;
use App\Models\Country;
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
        'business_type' => 'recruitment',
        'country_code' => 'MY',
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

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['payment_method' => 'bank', 'bank_name' => 'Sonali Bank', 'bank_branch' => 'Motijheel', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '0123 4567 89']))
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'CR-2026-0002')
            ->assertJsonPath('data.bank_name', 'Sonali Bank')
            ->assertJsonPath('data.bank_branch', 'Motijheel')
            ->assertJsonPath('data.bank_account_name', 'Afwan Associates')
            ->assertJsonPath('data.bank_account_number', '0123456789');

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
        'owner cash is capital' => ['owner', 'cash', 'capital'],
        'owner loan is a loan' => ['owner', 'loan', 'loan'],
    ]);

    it('requires the bank name, branch and account for a bank deposit', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['payment_method' => 'bank', 'bank_name' => '', 'bank_branch' => null, 'bank_account_name' => ' ', 'bank_account_number' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number']);
    });

    it('refuses a loan from a party: only owners lend money', function () {
        Sanctum::actingAs($user = User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['receipt_type' => 'loan']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt_type' => 'Only a company owner can give a loan.']);

        // A party loan saved before the rule can still be edited, and changed to cash.
        $old = CashReceipt::factory()->create([
            'party_id' => $party->id, 'receipt_type' => 'loan', 'date' => '2026-10-10', 'status' => 'pending', 'created_by' => $user->id,
        ]);
        $this->putJson("/api/accounts/cash-receipts/{$old->id}", receiptPayload($party, ['receipt_type' => 'loan', 'amount' => 300]))
            ->assertOk();
        $this->putJson("/api/accounts/cash-receipts/{$old->id}", receiptPayload($party))
            ->assertOk()
            ->assertJsonPath('data.receipt_type', 'cash');
    });

    it('requires how the money was received', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->postJson('/api/accounts/cash-receipts', receiptPayload(Party::factory()->create(), ['payment_method' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method' => 'Choose how the money was received.']);
    });

    it('records a mobile banking receipt with the provider and wallet number', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $owner = Party::factory()->owner()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($owner, [
            'payment_method' => 'mobile_banking', 'mobile_provider' => 'bkash', 'mobile_number' => '+880 1712-345678',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_method_label', 'Mobile banking')
            ->assertJsonPath('data.mobile_provider', 'bkash')
            ->assertJsonPath('data.mobile_provider_label', 'bKash')
            ->assertJsonPath('data.mobile_number', '01712345678')
            ->assertJsonPath('data.bank_name', null);

        approveAllAccountsEntries();
        // Mobile banking is not cash in hand.
        $this->getJson('/api/accounts/cash-receipts')
            ->assertJsonPath('meta.totals.cash', 0)
            ->assertJsonPath('meta.totals.mobile_banking', 2500);
    });

    it('requires a known provider and a valid wallet number for mobile banking', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['payment_method' => 'mobile_banking']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_provider', 'mobile_number']);
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, [
            'payment_method' => 'mobile_banking', 'mobile_provider' => 'paypal', 'mobile_number' => '0171234',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile_provider', 'mobile_number']);
        // Cash in hand drops any wallet details sent along.
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['mobile_provider' => 'nagad', 'mobile_number' => '01812345678']))
            ->assertCreated()
            ->assertJsonPath('data.mobile_provider', null)
            ->assertJsonPath('data.mobile_number', null);
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
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($client, ['date' => '2026-10-02', 'amount' => 200, 'payment_method' => 'bank', 'bank_name' => 'DBBL', 'bank_branch' => 'Uttara', 'bank_account_name' => 'Afwan Associates', 'bank_account_number' => '1234567']));
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
            ->assertJsonPath('meta.totals', ['count' => 3, 'amount' => 700, 'cash' => 500, 'bank' => 200, 'mobile_banking' => 0])
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

describe('cash receipt business and country', function () {
    it('stores the business and, for recruitment, the country of a party receipt', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['country_code' => ' my ']))
            ->assertCreated()
            ->assertJsonPath('data.business_type', 'recruitment')
            ->assertJsonPath('data.business_type_label', 'Recruitment')
            ->assertJsonPath('data.country.code', 'MY')
            ->assertJsonPath('data.country.name', 'Malaysia');

        // Other businesses take no country.
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['business_type' => 'solar']))
            ->assertCreated()
            ->assertJsonPath('data.business_type', 'solar')
            ->assertJsonPath('data.country', null);
    });

    it('needs a business for a party and a country for recruitment', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $party = Party::factory()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['business_type' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_type' => 'Choose the business.']);
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['business_type' => 'shipping']))
            ->assertJsonValidationErrors(['business_type']);
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['country_code' => null]))
            ->assertJsonValidationErrors(['country_code' => 'Choose the country.']);
        $inactive = Country::factory()->inactive()->create();
        $this->postJson('/api/accounts/cash-receipts', receiptPayload($party, ['country_code' => $inactive->code]))
            ->assertJsonValidationErrors(['country_code' => 'Choose an active country.']);
    });

    it('drops business and country for an owner receipt', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $owner = Party::factory()->owner()->create();

        $this->postJson('/api/accounts/cash-receipts', receiptPayload($owner, ['business_type' => 'shipping']))
            ->assertCreated()
            ->assertJsonPath('data.business_type', null)
            ->assertJsonPath('data.country', null);
    });

    it('offers the business types, countries and default country to the accounts team', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/accounts/receipt-options')
            ->assertOk()
            ->assertJsonPath('data.default_business_type', 'recruitment')
            ->assertJsonPath('data.business_types.0', ['value' => 'recruitment', 'label' => 'Recruitment'])
            ->assertJsonPath('data.business_types.3.value', 'railway')
            ->assertJsonPath('data.default_country.code', 'MY');

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->getJson('/api/accounts/receipt-options')->assertForbidden();
    });
});
