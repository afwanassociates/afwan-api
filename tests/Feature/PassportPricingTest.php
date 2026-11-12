<?php

use App\Models\Company;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('passport prices and dues', function () {
    beforeEach(function () {
        $this->malek = Party::factory()->agency()->create(['name' => 'Abdul Malek']);
        $this->karim = Party::factory()->agency()->create(['name' => 'Karim Travels']);
        $this->glove = Company::factory()->create(['name' => 'Top Glove', 'country_code' => 'MY']);
        $this->passport = fn (Party $party, array $state = []) => PassportEntry::factory()->create([
            'reference_id' => $party->id, 'company_id' => $this->glove->id, 'country_code' => 'BD', ...$state,
        ]);
        Sanctum::actingAs(User::factory()->accountsHead()->create());
    });

    it('lets only the accounts head change prices; the super admin reads them', function (string $role, int $read, int $write) {
        Sanctum::actingAs(User::factory()->{$role}()->create());
        $this->getJson('/api/payment-report/rates')->assertStatus($read);
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000])->assertStatus($write);
    })->with([
        ['accountsHead', 200, 200],
        ['superAdmin', 200, 403],
        ['admin', 403, 403],
        ['accounts', 403, 403],
    ]);

    it('fills unpriced passports from the company rate, then the reference rate', function () {
        $malek = [($this->passport)($this->malek), ($this->passport)($this->malek)];
        $karim = ($this->passport)($this->karim);

        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000])
            ->assertOk()->assertJson(['filled' => 3, 'updated' => 0, 'kept' => 0]);
        expect($karim->fresh()->price)->toBe('45000.00')->and($karim->fresh()->price_source)->toBe('company');

        // Malek's own rate: his passports have a price already, so they keep it unless asked.
        $this->putJson('/api/payment-report/rates/references', [
            'party_id' => $this->malek->id, 'company_id' => $this->glove->id, 'rate' => 40000,
        ])->assertOk()->assertJson(['filled' => 0, 'updated' => 0, 'kept' => 2]);
        $this->putJson('/api/payment-report/rates/references', [
            'party_id' => $this->malek->id, 'company_id' => $this->glove->id, 'rate' => 40000, 'update_existing' => true,
        ])->assertOk()->assertJson(['updated' => 2]);
        expect($malek[0]->fresh()->price)->toBe('40000.00')->and($malek[0]->fresh()->price_source)->toBe('reference');

        // A new passport is priced when entered; a company rate change leaves reference rates alone.
        $new = ($this->passport)($this->malek);
        expect($new->fresh()->price)->toBe('40000.00');
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 50000, 'update_existing' => true])
            ->assertJson(['updated' => 1]);
        expect($karim->fresh()->price)->toBe('50000.00')->and($new->fresh()->price)->toBe('40000.00');

        // The price is locked: a rate change without update_existing keeps it.
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 55000])->assertJson(['kept' => 1]);
        expect($karim->fresh()->price)->toBe('50000.00');

        $this->getJson('/api/payment-report/rates')
            ->assertJsonPath('companies.0.rate', 55000)
            ->assertJsonPath('references.0.reference.name', 'Abdul Malek')
            ->assertJsonPath('references.0.rate', 40000);
    });

    it('keeps a special price on one passport until removed', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $p = ($this->passport)($this->karim);

        $this->putJson("/api/payment-report/passports/{$p->id}/price", ['price' => 28000])
            ->assertOk()->assertJson(['price' => 28000, 'price_source' => 'special']);
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 60000, 'update_existing' => true]);
        expect($p->fresh()->price)->toBe('28000.00');

        $this->putJson("/api/payment-report/passports/{$p->id}/price", ['price' => null])
            ->assertJson(['price' => 60000, 'price_source' => 'company']);
    });

    it('never sends the price to the passport team', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $p = ($this->passport)($this->malek);
        expect($p->fresh()->toArray())->not->toHaveKey('price')->not->toHaveKey('price_source')
            ->and($this->glove->fresh()->toArray())->not->toHaveKey('rate');
    });

    it('shows the price, received and due per passport, company and reference', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $rahim = ($this->passport)($this->malek);
        $salam = ($this->passport)($this->malek);
        $this->putJson("/api/payment-report/passports/{$salam->id}/price", ['price' => 35000]);
        ($this->passport)($this->malek, ['current_return_id' => null]);

        // 60,000 marked full for Rahim and Salam (80,000 due): split 45:35.
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->malek, $this->glove, 60000, [$rahim, $salam]))->assertCreated();
        approveAllAccountsEntries();

        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->getJson("/api/payment-report?reference_id={$this->malek->id}")
            ->assertOk()
            ->assertJsonPath('summary.price_total', 125000)
            ->assertJsonPath('summary.paid_amount', 60000)
            ->assertJsonPath('summary.due', 65000)
            ->assertJsonPath('data.0.price_total', 125000)
            ->assertJsonPath('data.0.due', 65000)
            ->assertJsonPath('data.0.companies.0.company_rate', 45000)
            ->assertJsonPath('data.0.companies.0.reference_rate', null)
            ->assertJsonPath('data.0.companies.0.price_total', 125000)
            ->assertJsonPath('data.0.companies.0.due', 65000)
            ->assertJsonPath('data.0.companies.0.passports.0.price', 45000)
            ->assertJsonPath('data.0.companies.0.passports.0.paid_amount', 33750)
            ->assertJsonPath('data.0.companies.0.passports.0.due', 11250)
            ->assertJsonPath('data.0.companies.0.passports.0.short', true)
            ->assertJsonPath('data.0.companies.0.passports.0.receipts.0.split_by_price', true)
            ->assertJsonPath('data.0.companies.0.passports.1.price_source', 'special')
            ->assertJsonPath('data.0.companies.0.passports.1.paid_amount', 26250)
            ->assertJsonPath('data.0.companies.0.passports.2.status', 'unpaid')
            ->assertJsonPath('data.0.companies.0.passports.2.due', 45000)
            ->assertJsonPath('data.0.companies.0.passports.2.short', false);
    });
    it('has no due (not a credit) for passports whose price was removed', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $a = ($this->passport)($this->malek);
        $b = ($this->passport)($this->malek);
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->malek, $this->glove, 80000, [$a, $b], false))->assertCreated();
        approveAllAccountsEntries();

        // The standard rate is removed from every passport.
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => null, 'update_existing' => true])->assertOk();
        $this->getJson('/api/payment-report')
            ->assertJsonPath('summary.paid_amount', 80000)
            ->assertJsonPath('summary.no_price', 2)
            ->assertJsonPath('summary.due', null)
            ->assertJsonPath('data.0.due', null)
            ->assertJsonPath('data.0.companies.0.due', null)
            ->assertJsonPath('data.0.companies.0.passports.0.due', null);

        // Once one has a price again, only its own share counts: 45,000 - 40,000 (split evenly).
        $this->putJson("/api/payment-report/passports/{$a->id}/price", ['price' => 45000]);
        $this->getJson('/api/payment-report')
            ->assertJsonPath('summary.due', 5000)
            ->assertJsonPath('data.0.companies.0.due', 5000)
            ->assertJsonPath('data.0.companies.0.no_price', 1);
    });

    it('refuses cash for a passport without a price, and the cash receive form shows prices', function () {
        $p = ($this->passport)($this->malek);
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson("/api/accounts/parties/{$this->malek->id}/passports")
            ->assertJsonPath('data.0.passports.0.price', null)
            ->assertJsonPath('data.0.passports.0.can_tick', false);
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->malek, $this->glove, 10000, [$p], false))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['companies.0.passport_ids' => "Passport #{$p->entry_no} has no price."]);

        // The party's own rate prices it: 40,000, 10,000 paid, 30,000 due.
        Sanctum::actingAs(User::factory()->accountsHead()->create());
        $this->putJson('/api/payment-report/rates/references', [
            'party_id' => $this->malek->id, 'company_id' => $this->glove->id, 'rate' => 40000,
        ])->assertOk();
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->malek, $this->glove, 10000, [$p], false))->assertCreated();
        $this->getJson("/api/accounts/parties/{$this->malek->id}/passports")
            ->assertJsonPath('data.0.passports.0.price', 40000)
            ->assertJsonPath('data.0.passports.0.price_source', 'reference')
            ->assertJsonPath('data.0.passports.0.paid', 10000)
            ->assertJsonPath('data.0.passports.0.due', 30000)
            ->assertJsonPath('data.0.passports.0.can_tick', true);
    });
});
