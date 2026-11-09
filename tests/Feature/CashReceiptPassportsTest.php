<?php

use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * @param  array<int, array{company: Company, amount: int|float, passports: list<PassportEntry>, full?: bool}>  $lines
 * @return array<string, mixed>
 */
function passportReceipt(Party $party, array $lines, array $overrides = []): array
{
    return plainPartyReceipt($party, [
        'amount' => array_sum(array_column($lines, 'amount')),
        'companies' => array_map(fn (array $line) => [
            'company_id' => $line['company']->id,
            'amount' => $line['amount'],
            'is_full_payment' => $line['full'] ?? true,
            'passport_ids' => array_map(fn (PassportEntry $p) => $p->id, $line['passports']),
        ], $lines),
        ...$overrides,
    ]);
}

/**
 * @return array<string, mixed>
 */
function plainPartyReceipt(Party $party, array $overrides = []): array
{
    return [
        'date' => '2026-10-10',
        'party_type' => 'client',
        'party_id' => $party->id,
        'receipt_type' => 'cash',
        'business_type' => 'recruitment',
        'country_code' => 'MY',
        'amount' => 2500,
        'payment_method' => 'cash',
        ...$overrides,
    ];
}

describe('cash receipts paying for passports', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->party = Party::factory()->agency()->create();
        $this->alNoor = Company::factory()->create(['name' => 'Al Noor', 'country_code' => 'MY']);
        $this->gulf = Company::factory()->create(['name' => 'Gulf Star', 'country_code' => 'MY']);
        $this->riyadh = Company::factory()->create(['name' => 'Riyadh Co', 'country_code' => 'SA']);
        $make = fn (Company $company, int $count, array $state = []) => PassportEntry::factory()->count($count)->create([
            'reference_id' => $this->party->id, 'company_id' => $company->id, 'country_code' => 'BD', ...$state,
        ])->all();
        $this->noor = $make($this->alNoor, 3);
        $this->star = $make($this->gulf, 2);
        $this->make = $make;
    });

    it('lists the party\'s passports by company, all unpaid at first', function () {
        // Bangladeshi passports going to Malaysia: the destination is the company's country.
        ($this->make)($this->riyadh, 1);
        ($this->make)($this->alNoor, 1, ['deleted_at' => now()]);
        PassportEntry::factory()->create(['company_id' => $this->alNoor->id]);

        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertOk()
            ->assertJsonPath('data.0.company.name', 'Al Noor')
            ->assertJsonPath('data.0.company.country_code', 'MY')
            ->assertJsonPath('data.0.counts', ['total' => 3, 'paid' => 0, 'pending' => 0, 'part_paid' => 0, 'unpaid' => 3])
            ->assertJsonPath('data.1.company.name', 'Gulf Star')
            ->assertJsonPath('meta.counts.total', 5);

        // Without a country: every country.
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports")->assertJsonPath('meta.counts.total', 6);
    });

    it('records the companies and passports a receipt pays, pending until approved, then paid', function () {
        $response = $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 40000, 'passports' => [$this->noor[0], $this->noor[1]]],
            ['company' => $this->gulf, 'amount' => 20000, 'passports' => [$this->star[0]]],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.amount', 60000)
            ->assertJsonPath('data.passport_count', 3)
            ->assertJsonPath('data.companies.0.company_name', 'Al Noor')
            ->assertJsonPath('data.companies.0.amount', 40000)
            ->assertJsonCount(2, 'data.companies.0.passports');
        $receiptNo = $response->json('data.receipt_no');

        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertJsonPath('data.0.counts', ['total' => 3, 'paid' => 0, 'pending' => 2, 'part_paid' => 0, 'unpaid' => 1])
            ->assertJsonPath('data.0.passports.0.receipts.0.receipt_no', $receiptNo)
            ->assertJsonPath('data.0.passports.0.can_tick', false);

        approveAllAccountsEntries();
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertJsonPath('meta.counts', ['total' => 5, 'paid' => 3, 'pending' => 0, 'part_paid' => 0, 'unpaid' => 2]);
        // The ledger still gets one payment for the whole amount.
        expect($this->party->transactions()->sole()->amount)->toBe('60000.00');
    });

    it('lets a passport be paid in parts over several receipts until a full payment', function () {
        $part = fn (array $passports, bool $full) => $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 5000, 'passports' => $passports, 'full' => $full],
        ]));

        $first = $part([$this->noor[0], $this->noor[1]], false)
            ->assertCreated()
            ->assertJsonPath('data.companies.0.is_full_payment', false)
            ->json('data.receipt_no');
        approveAllAccountsEntries();
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertJsonPath('data.0.counts', ['total' => 3, 'paid' => 0, 'pending' => 0, 'part_paid' => 2, 'unpaid' => 1])
            ->assertJsonPath('data.0.passports.0.status', 'part_paid')
            ->assertJsonPath('data.0.passports.0.can_tick', true);

        // A second part, then the rest as a full payment.
        $part([$this->noor[0]], false)->assertCreated();
        $last = $part([$this->noor[0]], true)->assertCreated()->json('data.receipt_no');
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertJsonPath('data.0.passports.0.status', 'pending')
            ->assertJsonCount(3, 'data.0.passports.0.receipts')
            ->assertJsonPath('data.0.passports.0.receipts.0.receipt_no', $first)
            ->assertJsonPath('data.0.passports.0.can_tick', false);
        approveAllAccountsEntries();
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY")
            ->assertJsonPath('data.0.passports.0.status', 'paid')
            ->assertJsonPath('data.0.passports.1.status', 'part_paid');

        // Paid off: no more receipts for it, part or full.
        $part([$this->noor[0]], false)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['companies.0.passport_ids' => "Passport #{$this->noor[0]->entry_no} is already fully paid on {$last}."]);
    });

    it('needs the amounts to add up to the receipt amount', function () {
        $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 40000, 'passports' => [$this->noor[0]]],
        ], ['amount' => 45000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount must equal the total of the companies (40,000.00).']);
    });

    it('refuses passports that are paid already, of another party, company or country, or deleted', function () {
        $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 100, 'passports' => [$this->noor[0]]],
        ]))->assertCreated();
        $receiptNo = CashReceipt::query()->sole()->receipt_no;

        $other = PassportEntry::factory()->create(['company_id' => $this->alNoor->id]);
        [$saudi] = ($this->make)($this->riyadh, 1);
        [$deleted] = ($this->make)($this->alNoor, 1, ['deleted_at' => now()]);

        $cases = [
            [$this->noor[0], "Passport #{$this->noor[0]->entry_no} is already fully paid on {$receiptNo}."],
            [$other, "Passport #{$other->entry_no} is not this party's."],
            [$this->star[0], "Passport #{$this->star[0]->entry_no} is not for this company."],
            // A Saudi company's passport on a Malaysia receipt.
            [$saudi, "Passport #{$saudi->entry_no} is for another country.", $this->riyadh],
            [$deleted, "Passport #{$deleted->entry_no} was deleted."],
        ];
        foreach ($cases as $case) {
            [$passport, $message] = $case;
            $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
                ['company' => $case[2] ?? $this->alNoor, 'amount' => 100, 'passports' => [$passport]],
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['companies.0.passport_ids' => $message]);
        }

        $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 100, 'passports' => []],
        ]))->assertJsonValidationErrors(['companies.0.passport_ids' => 'Tick at least one passport.']);
    });

    it('takes passports only for a party\'s recruitment cash', function () {
        $lines = [['company' => $this->alNoor, 'amount' => 100, 'passports' => [$this->noor[0]]]];

        $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, $lines, ['business_type' => 'land']))
            ->assertJsonValidationErrors(['companies' => 'Only a party\'s recruitment cash pays for passports.']);
        // An empty list is fine everywhere, and a receipt without passports still works.
        $this->postJson('/api/accounts/cash-receipts', plainPartyReceipt($this->party, ['business_type' => 'land', 'companies' => []]))
            ->assertCreated()
            ->assertJsonPath('data.passport_count', 0);
    });

    it('replaces the passports when the receipt is edited and frees them when it is deleted', function () {
        $id = $this->postJson('/api/accounts/cash-receipts', passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 300, 'passports' => [$this->noor[0], $this->noor[1]]],
        ]))->json('data.id');

        // Editing: its own passports are its own (ticked) and may be kept.
        $this->getJson("/api/accounts/parties/{$this->party->id}/passports?country_code=MY&receipt_id={$id}")
            ->assertJsonPath('data.0.passports.0.on_this_receipt', true)
            ->assertJsonPath('data.0.passports.2.on_this_receipt', false);

        $this->putJson("/api/accounts/cash-receipts/{$id}", passportReceipt($this->party, [
            ['company' => $this->alNoor, 'amount' => 200, 'passports' => [$this->noor[1], $this->noor[2]]],
            ['company' => $this->gulf, 'amount' => 100, 'passports' => [$this->star[1]]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.passport_count', 3)
            ->assertJsonPath('data.companies.1.company_name', 'Gulf Star');
        expect(DB::table('cash_receipt_passports')->pluck('passport_entry_id')->sort()->values()->all())
            ->toBe(collect([$this->noor[1]->id, $this->noor[2]->id, $this->star[1]->id])->sort()->values()->all());

        $this->deleteJson("/api/accounts/cash-receipts/{$id}")->assertNoContent();
        expect(DB::table('cash_receipt_passports')->count())->toBe(0)
            ->and(DB::table('cash_receipt_lines')->count())->toBe(0);
    });

    it('keeps the passports to the accounts team', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/accounts/parties/{$this->party->id}/passports")->assertForbidden();
    });
});
