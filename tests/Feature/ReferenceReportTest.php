<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A passport of the reference at a stored workflow position (the report reads
 * only current_stage and stage_status).
 */
function referencePassport(Reference $reference, string $stage, string $status = 'waiting', ?Company $company = null): PassportEntry
{
    $passport = PassportEntry::factory()->create([
        'reference_id' => $reference->id,
        ...($company ? ['company_id' => $company->id] : []),
    ]);
    PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update(['current_stage' => $stage, 'stage_status' => $status]);

    return $passport->fresh();
}

describe('reference report', function () {
    beforeEach(function () {
        $this->karim = Reference::factory()->person()->create(['name' => 'Abdul Karim', 'phone' => '01711-000000']);
        $this->star = Reference::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        $this->idle = Reference::factory()->person()->create(['name' => 'Zahid Hasan']);
        $this->gulf = Company::factory()->create(['name' => 'Gulf Star']);

        referencePassport($this->karim, 'passport', 'not_started');
        referencePassport($this->karim, 'medical', 'unfit');
        referencePassport($this->karim, 'visa', 'waiting', $this->gulf);
        referencePassport($this->karim, 'completed', 'waiting', $this->gulf);
        referencePassport($this->star, 'medical', 'pending');
        // Deleted passports are left out.
        referencePassport($this->star, 'calling', 'waiting')->delete();
    });

    it('lists one row per reference with the company report counts, no quota or balance', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson('/api/reports/reference-report')->assertOk();

        expect($response->json('data.*.name'))->toBe(['Abdul Karim', 'Sea Star Overseas', 'Zahid Hasan'])
            ->and($response->json('data.0'))->toMatchArray([
                'id' => $this->karim->id,
                'type' => 'person',
                'type_label' => 'Person',
                'phone' => '01711-000000',
                'is_active' => true,
                'total' => 4,
                'not_started' => 1,
                'medical_unfit' => 1,
                'medical_fit' => 2,
                'calling_done' => 2,
                'visa_done' => 1,
                'flight_done' => 1,
                'in_process' => 2,
            ])
            ->and($response->json('data.0'))->not->toHaveKeys(['quota', 'balance'])
            ->and($response->json('data.1'))->toMatchArray(['total' => 1, 'medical_pending' => 1, 'calling_done' => 0])
            ->and($response->json('data.2.total'))->toBe(0)
            ->and($response->json('totals'))->toMatchArray(['total' => 5, 'medical_unfit' => 1, 'in_process' => 3])
            ->and($response->json('totals'))->not->toHaveKeys(['quota', 'balance'])
            ->and($response->json('meta.total'))->toBe(3);
    });

    it('filters by search, type and reference, and sorts', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        expect($this->getJson('/api/reports/reference-report?search=0171')->json('data.*.name'))->toBe(['Abdul Karim'])
            ->and($this->getJson('/api/reports/reference-report?type=agency')->json('data.*.name'))->toBe(['Sea Star Overseas'])
            ->and($this->getJson("/api/reports/reference-report?reference_id={$this->star->id}")->json('data.*.name'))->toBe(['Sea Star Overseas'])
            ->and($this->getJson('/api/reports/reference-report?sort=-total')->json('data.*.name'))->toBe(['Abdul Karim', 'Sea Star Overseas', 'Zahid Hasan'])
            ->and($this->getJson('/api/reports/reference-report?sort=-type')->json('data.*.name'))->toBe(['Abdul Karim', 'Zahid Hasan', 'Sea Star Overseas']);

        $this->getJson('/api/reports/reference-report?sort=balance')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/reports/reference-report?sort=quota')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });

    it('counts only one company\'s passports with the company filter, and names the filters', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());

        $this->getJson("/api/reports/reference-report?company_id={$this->gulf->id}&reference_id={$this->karim->id}")
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Abdul Karim'])
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('totals.total', 2)
            ->assertJsonPath('filters.company.name', 'Gulf Star')
            ->assertJsonPath('filters.reference.name', 'Abdul Karim');
    });

    it('lists a reference\'s passports, newest first', function () {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/reports/reference-report/{$this->karim->id}/passports")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.reference.id', $this->karim->id)
            ->assertJsonStructure(['data' => [['latest_status', 'can_edit_passport', 'workflow']], 'meta']);
    });

    it('exports the report and a reference\'s passports as CSV', function () {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $csv = $this->get('/api/reports/reference-report?export=csv')->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));

        expect($lines[0])->toContain('Reference', 'Type', 'Phone', 'Total', 'In process')
            ->and($lines[0])->not->toContain('Balance')
            ->and($lines[1][1])->toBe('Abdul Karim')
            ->and(end($lines)[0])->toBe('Total');

        $this->get("/api/reports/reference-report/{$this->karim->id}/passports?export=csv")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    });

    it('is open to every staff role', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/reports/reference-report')->assertOk();
        $this->getJson("/api/reports/reference-report/{$this->karim->id}/passports")->assertOk();
    })->with(['dataEntry', 'operationHead', 'accounts', 'accountsHead', 'admin', 'superAdmin']);

    it('rejects guests', function () {
        $this->getJson('/api/reports/reference-report')->assertUnauthorized();
    });
});
