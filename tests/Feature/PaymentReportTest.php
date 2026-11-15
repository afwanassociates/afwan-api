<?php

use App\Models\Company;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\PassportReturnService;
use Laravel\Sanctum\Sanctum;

describe('payment report', function () {
    beforeEach(function () {
        $this->party = Party::factory()->agency()->create(['name' => 'Alpha Agency']);
        $this->other = Party::factory()->agency()->create(['name' => 'Beta Agency']);
        // A standard rate: cash is received only against priced passports.
        $this->company = Company::factory()->create(['name' => 'Al Noor', 'country_code' => 'MY', 'rate' => 20000]);
        $make = fn (Party $party, int $count) => PassportEntry::factory()->count($count)->create([
            'reference_id' => $party->id, 'company_id' => $this->company->id, 'country_code' => 'BD',
        ])->all();
        $this->mine = $make($this->party, 3);
        $this->theirs = $make($this->other, 1);
    });

    it('is for the accounts head and the super admin only', function (string $state, int $code) {
        Sanctum::actingAs(User::factory()->{$state}()->create());
        $this->getJson('/api/payment-report')->assertStatus($code);
    })->with([
        ['accountsHead', 200],
        ['superAdmin', 200],
        ['admin', 403],
        ['accounts', 403],
    ]);

    it('groups by reference, then company with the amount paid, then passport', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        // 40,000 for two passports as a full payment; 5,000 part payment for the third.
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->party, $this->company, 40000, [$this->mine[0], $this->mine[1]]))->assertCreated();
        approveAllAccountsEntries();
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->party, $this->company, 5000, [$this->mine[2]], false))->assertCreated();

        // Another company of the same reference, unpaid.
        $gulf = Company::factory()->create(['name' => 'Gulf Star', 'country_code' => 'MY']);
        PassportEntry::factory()->create(['reference_id' => $this->party->id, 'company_id' => $gulf->id, 'country_code' => 'BD']);

        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->getJson("/api/payment-report?reference_id={$this->party->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.paid', 2)
            ->assertJsonPath('summary.part_paid', 1)
            ->assertJsonPath('summary.unpaid', 1)
            ->assertJsonPath('summary.paid_amount', 40000)
            ->assertJsonPath('summary.pending_amount', 5000)
            ->assertJsonPath('filters.reference.name', 'Alpha Agency')
            // Reference → company (what it paid for it) → passports.
            ->assertJsonPath('data.0.reference.name', 'Alpha Agency')
            ->assertJsonPath('data.0.paid_amount', 40000)
            ->assertJsonPath('data.0.pending_amount', 5000)
            ->assertJsonPath('data.0.counts.total', 4)
            ->assertJsonPath('data.0.companies.0.company.name', 'Al Noor')
            ->assertJsonPath('data.0.companies.0.paid_amount', 40000)
            ->assertJsonPath('data.0.companies.0.pending_amount', 5000)
            ->assertJsonPath('data.0.companies.0.receipt_count', 2)
            ->assertJsonPath('data.0.companies.0.counts', [
                'total' => 3, 'paid' => 2, 'pending' => 0, 'part_paid' => 1, 'unpaid' => 0,
                'returned' => 0, 'returned_paid' => 0, 'returned_credit' => 0,
            ])
            ->assertJsonPath('data.0.companies.0.passports.0.status', 'paid')
            ->assertJsonPath('data.0.companies.0.passports.0.paid_amount', 20000)
            ->assertJsonPath('data.0.companies.0.passports.0.receipts.0.line_passports', 2)
            ->assertJsonPath('data.0.companies.0.passports.2.status', 'part_paid')
            ->assertJsonPath('data.0.companies.0.passports.2.pending_amount', 5000)
            ->assertJsonPath('data.0.companies.1.company.name', 'Gulf Star')
            ->assertJsonPath('data.0.companies.1.paid_amount', 0)
            ->assertJsonPath('data.0.companies.1.passports.0.status', 'unpaid');

        // Unpaid: both references, only their unpaid passports; the summary keeps every status.
        $this->getJson('/api/payment-report?status=unpaid')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.reference.name', 'Alpha Agency')
            ->assertJsonCount(1, 'data.0.companies')
            ->assertJsonPath('data.0.companies.0.company.name', 'Gulf Star')
            ->assertJsonPath('data.1.companies.0.passports.0.id', $this->theirs[0]->id)
            ->assertJsonPath('summary.total', 5);
    });

    it('counts returned passports apart, with what was paid for them as credit', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        // 40,000 full payment for the first two passports, approved.
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->party, $this->company, 40000, [$this->mine[0], $this->mine[1]]))->assertCreated();
        approveAllAccountsEntries();

        // The second (paid) and the third (unpaid) go back to their owners.
        $head = User::factory()->operationHead()->create();
        $returns = app(PassportReturnService::class);
        foreach ([$this->mine[1], $this->mine[2]] as $p) {
            $returns->returnPassport($p->fresh(), ['return_date' => '2026-11-11', 'receiver_name' => 'Owner', 'receiver_phone' => '01700000000'], $head);
        }

        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $counts = [
            'total' => 1, 'paid' => 1, 'pending' => 0, 'part_paid' => 0, 'unpaid' => 0,
            'returned' => 2, 'returned_paid' => 1, 'returned_credit' => 20000,
        ];
        $this->getJson("/api/payment-report?reference_id={$this->party->id}")
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.unpaid', 0)
            ->assertJsonPath('summary.returned', 2)
            ->assertJsonPath('summary.returned_paid', 1)
            ->assertJsonPath('summary.returned_credit', 20000)
            ->assertJsonPath('summary.paid_amount', 40000)
            ->assertJsonPath('summary.price_total', 20000)
            // The 20,000 paid for the returned passport is the reference's credit.
            ->assertJsonPath('summary.due', -20000)
            ->assertJsonPath('data.0.counts', $counts)
            ->assertJsonPath('data.0.companies.0.counts', $counts)
            ->assertJsonPath('data.0.companies.0.passports.1.status', 'returned')
            ->assertJsonPath('data.0.companies.0.passports.1.due', -20000)
            ->assertJsonPath('data.0.companies.0.passports.2.status', 'returned')
            ->assertJsonPath('data.0.companies.0.passports.2.due', 0);

        // The Returned tab lists only them; the summary keeps every status.
        $this->getJson('/api/payment-report?status=returned')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(2, 'data.0.companies.0.passports')
            ->assertJsonPath('data.0.companies.0.passports.0.id', $this->mine[1]->id)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.returned', 2);
    });
});
