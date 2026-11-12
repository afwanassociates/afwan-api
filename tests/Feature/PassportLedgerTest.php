<?php

use App\Models\Company;
use App\Models\Party;
use App\Models\PartyTransaction;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\PassportReturnService;
use App\Support\LocalDate;
use Laravel\Sanctum\Sanctum;

describe('passport prices on the party ledger', function () {
    beforeEach(function () {
        $this->malek = Party::factory()->agency()->create(['name' => 'Abdul Malek']);
        $this->karim = Party::factory()->agency()->create(['name' => 'Karim Travels']);
        $this->glove = Company::factory()->create(['name' => 'Top Glove', 'country_code' => 'MY']);
        $this->passport = fn (Party $party) => PassportEntry::factory()->create([
            'reference_id' => $party->id, 'company_id' => $this->glove->id, 'country_code' => 'BD',
        ]);
        $this->head = User::factory()->accountsHead()->create();
        Sanctum::actingAs($this->head);
        $this->line = fn (PassportEntry $p) => PartyTransaction::query()->where('passport_entry_id', $p->id)->first();
    });

    it('adds an approved receivable line per priced passport, following the price', function () {
        $a = ($this->passport)($this->malek);
        $b = ($this->passport)($this->malek);
        expect(PartyTransaction::query()->count())->toBe(0);

        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000])->assertOk();
        $line = ($this->line)($a);
        expect($line->type->value)->toBe('receivable')
            ->and($line->amount)->toBe('45000.00')
            ->and($line->status->value)->toBe('approved')
            ->and($line->party_id)->toBe($this->malek->id)
            ->and($line->date->format('Y-m-d'))->toBe(LocalDate::today()->toDateString())
            ->and($line->note)->toBe("Passport #{$a->entry_no} {$a->passport_name} · Top Glove")
            ->and($line->isManual())->toBeFalse();

        // The ledger: 90,000 owed, the line names its passport.
        $this->getJson("/api/accounts/parties/{$this->malek->id}/ledger")
            ->assertOk()
            ->assertJsonPath('closing_balance', 90000)
            ->assertJsonPath('lines.0.reference', "Passport #{$a->entry_no}")
            ->assertJsonPath('lines.0.is_manual', false);

        // Reference rate (existing passports too), a special price, then removing it.
        $this->putJson('/api/payment-report/rates/references', [
            'party_id' => $this->malek->id, 'company_id' => $this->glove->id, 'rate' => 40000, 'update_existing' => true,
        ])->assertOk();
        expect(($this->line)($a)->amount)->toBe('40000.00');
        $this->putJson("/api/payment-report/passports/{$b->id}/price", ['price' => 38000])->assertOk();
        expect(($this->line)($b)->amount)->toBe('38000.00');
        $this->getJson("/api/accounts/parties/{$this->malek->id}/ledger")->assertJsonPath('closing_balance', 78000);
        $this->putJson("/api/payment-report/passports/{$b->id}/price", ['price' => null])->assertOk();
        expect(($this->line)($b)->amount)->toBe('40000.00');

        // A new passport gets its line when entered.
        $c = ($this->passport)($this->malek);
        expect(($this->line)($c)->amount)->toBe('40000.00');
    });

    it('moves the line with the reference and removes it for a returned or deleted passport', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $p = ($this->passport)($this->malek);

        $p->update(['reference_id' => $this->karim->id]);
        expect(($this->line)($p)->party_id)->toBe($this->karim->id);

        $returns = app(PassportReturnService::class);
        $returns->returnPassport($p->fresh(), ['return_date' => '2026-11-11', 'receiver_name' => 'Owner', 'receiver_phone' => '01700000000'], $this->head);
        expect(($this->line)($p))->toBeNull();
        $returns->undo($p->fresh(), $this->head);
        expect(($this->line)($p)->amount)->toBe('45000.00');

        $p->fresh()->delete();
        expect(($this->line)($p))->toBeNull();
        PassportEntry::withTrashed()->find($p->id)->restore();
        expect(($this->line)($p)->amount)->toBe('45000.00');
    });

    it('does not let anyone edit or delete a passport price line by hand', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $line = ($this->line)(($this->passport)($this->malek));

        $this->patchJson("/api/accounts/party-transactions/{$line->id}", ['date' => '2026-11-11', 'type' => 'receivable', 'amount' => 1])->assertForbidden();
        $this->deleteJson("/api/accounts/party-transactions/{$line->id}")->assertForbidden();
        expect($line->fresh()->amount)->toBe('45000.00');
    });

    it('shows the passport payments against the price lines', function () {
        $this->putJson("/api/payment-report/rates/companies/{$this->glove->id}", ['rate' => 45000]);
        $a = ($this->passport)($this->malek);
        $b = ($this->passport)($this->malek);

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->postJson('/api/accounts/cash-receipts', paymentReportReceipt($this->malek, $this->glove, 60000, [$a, $b], false))->assertCreated();
        approveAllAccountsEntries();

        // 90,000 owed - 60,000 paid = the payment report's due.
        Sanctum::actingAs($this->head);
        $this->getJson("/api/accounts/parties/{$this->malek->id}/ledger")->assertJsonPath('closing_balance', 30000);
        $this->getJson("/api/payment-report?reference_id={$this->malek->id}")->assertJsonPath('data.0.due', 30000);
    });
});
