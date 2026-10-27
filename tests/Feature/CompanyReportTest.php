<?php

use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A passport of the company at the given point of the workflow.
 */
function companyPassport(Company $company, string $state): PassportEntry
{
    $passport = PassportEntry::factory()->create([
        'company_id' => $company->id,
        'passport_received_date' => '2026-01-15',
        'passport_expiry_date' => '2035-01-01',
        // Slip date entered ("not_started" leaves it out).
        'medical_slip_date' => $state === 'not_started' ? null : '2026-01-15',
        // Marked Online, so a fit passport is available for Calling.
        'entry_mode' => 'online',
    ]);

    if (in_array($state, ['not_started', 'pending'], true)) {
        return $passport->fresh();
    }

    $medical = MedicalRecord::factory()->for($passport)->on('2026-04-01');
    ($state === 'unfit' ? $medical->unfit() : $medical)->create();

    $completed = match ($state) {
        'visa_waiting' => ['calling'],
        'flight_waiting' => ['calling', 'visa', 'bmet'],
        'completed' => ['calling', 'visa', 'bmet', 'flight'],
        default => [],
    };

    foreach ($completed as $index => $step) {
        $record = PassportStepRecord::factory()->for($passport)->step($step)->on('2026-04-0'.($index + 2));
        ($step === 'flight' ? $record->flight('2026-04-30') : $record)->create();
    }

    if ($state === 'calling_in_process') {
        PassportStepRecord::factory()->for($passport)->status(StepStatus::InProcess)->on('2026-04-10')->create();
    }

    return $passport->fresh();
}

/**
 * Three companies: A (quota 10) with passports at every point, B (no quota)
 * with one pending passport, C (quota 3) with none.
 *
 * @return array{a: Company, b: Company, c: Company}
 */
function reportCompanies(): array
{
    $a = Company::factory()->create(['name' => 'Alpha Builders', 'country_code' => 'MY', 'quota' => 10]);
    $b = Company::factory()->create(['name' => 'Beta Foods', 'country_code' => 'SA']);
    $c = Company::factory()->create(['name' => 'Gamma Care', 'country_code' => 'MY', 'quota' => 3]);

    foreach (['pending', 'unfit', 'unfit', 'fit', 'calling_in_process', 'visa_waiting', 'flight_waiting', 'completed'] as $state) {
        companyPassport($a, $state);
    }
    companyPassport($a, 'completed')->delete();
    companyPassport($b, 'pending');

    return ['a' => $a, 'b' => $b, 'c' => $c];
}

/**
 * The CSV body as rows (BOM stripped).
 *
 * @return list<list<string>>
 */
function csvRows(string $body): array
{
    return array_map(fn (string $line) => str_getcsv($line, escape: ''), explode("\n", trim(substr($body, 3))));
}

describe('company fields', function () {
    it('saves only the name, country and quota (other fields are ignored)', function () {
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson('/api/admin/companies', [
            'name' => 'Desert Rose Construction',
            'country_code' => 'my',
            'quota' => 25,
            'agent_name' => 'Ahmad Faiz',
        ])
            ->assertCreated()
            ->assertJsonPath('data.quota', 25)
            ->assertJsonMissingPath('data.agent_name');
    });

    it('edits the quota of an existing company', function () {
        $existing = Company::factory()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/admin/companies/{$existing->id}", ['quota' => 5])
            ->assertOk()
            ->assertJsonPath('data.quota', 5)
            ->assertJsonPath('data.name', $existing->name);
    });

    it('rejects bad values', function (array $payload, string $field) {
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson('/api/admin/companies', ['name' => 'Some Company', 'country_code' => 'MY', 'quota' => 5, ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'no country' => [['country_code' => null], 'country_code'],
        'no quota' => [['quota' => null], 'quota'],
        'quota 0' => [['quota' => 0], 'quota'],
        'negative quota' => [['quota' => -1], 'quota'],
        'fractional quota' => [['quota' => 2.5], 'quota'],
        'name too long' => [['name' => str_repeat('a', 151)], 'name'],
    ]);
});

describe('company report', function () {
    it('counts each company\'s passports from their stage', function () {
        ['a' => $a, 'b' => $b, 'c' => $c] = reportCompanies();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $response = $this->getJson('/api/reports/companies')->assertOk();

        expect($response->json('data.*.name'))->toBe(['Alpha Builders', 'Beta Foods', 'Gamma Care'])
            ->and($response->json('data.0'))->toBe([
                'id' => $a->id,
                'name' => 'Alpha Builders',
                'country_code' => 'MY',
                'quota' => 10,
                'total' => 8,
                // Unfit passports count only as unfit.
                'medical_fit' => 5,
                'medical_unfit' => 2,
                'medical_pending' => 1,
                'not_started' => 0,
                'calling_done' => 3,
                'visa_done' => 2,
                'bmet_done' => 2,
                'flight_done' => 1,
                // Flight not confirmed and not unfit.
                'in_process' => 5,
                'balance' => 2,
            ])
            ->and($response->json('data.1'))->toMatchArray(['total' => 1, 'medical_pending' => 1, 'in_process' => 1, 'quota' => null, 'balance' => null])
            ->and($response->json('data.2'))->toMatchArray(['total' => 0, 'medical_fit' => 0, 'quota' => 3, 'balance' => 3])
            ->and($response->json('totals'))->toBe([
                'quota' => 13, 'total' => 9, 'medical_fit' => 5, 'medical_unfit' => 2, 'medical_pending' => 2, 'not_started' => 0,
                'calling_done' => 3, 'visa_done' => 2, 'bmet_done' => 2, 'flight_done' => 1, 'in_process' => 6,
                'balance' => 5,
            ]);
    });

    it('searches, filters by country and sorts', function () {
        ['a' => $a, 'b' => $b, 'c' => $c] = reportCompanies();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($this->getJson('/api/reports/companies?search=alpha')->json('data.*.id'))->toBe([$a->id])
            ->and($this->getJson('/api/reports/companies?country_code=my')->json('data.*.id'))->toBe([$a->id, $c->id])
            ->and($this->getJson('/api/reports/companies?country_code=MY')->json('totals.total'))->toBe(8)
            ->and($this->getJson('/api/reports/companies?sort=-total')->json('data.*.id'))->toBe([$a->id, $b->id, $c->id])
            ->and($this->getJson('/api/reports/companies?sort=total')->json('data.*.id'))->toBe([$c->id, $b->id, $a->id])
            ->and($this->getJson('/api/reports/companies?sort=-balance')->json('data.*.id'))->toBe([$c->id, $a->id, $b->id])
            ->and($this->getJson('/api/reports/companies?sort=-name&per_page=2')->json('data.*.id'))->toBe([$c->id, $b->id]);

        $this->getJson('/api/reports/companies?sort=bogus')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/reports/companies?export=pdf')->assertUnprocessable()->assertJsonValidationErrors('export');
    });

    it('matches the seeded data', function () {
        $this->seed();
        Sanctum::actingAs(User::factory()->admin()->create());

        $totals = $this->getJson('/api/reports/companies?per_page=100')->assertOk()->json('totals');
        $stages = PassportEntry::query()->get(['current_stage', 'stage_status']);

        expect($totals['total'])->toBe(PassportEntry::count())
            ->and($totals['medical_unfit'])->toBe($stages->where('current_stage', 'medical')->where('stage_status', 'unfit')->count())
            ->and($totals['medical_pending'])->toBe($stages->where('current_stage', 'medical')->where('stage_status', 'pending')->count())
            ->and($totals['medical_fit'])->toBe($stages->filter(fn (PassportEntry $p) => ! in_array($p->current_stage, ['passport', 'medical'], true) || $p->stage_status === 'fit')->count())
            ->and($totals['not_started'])->toBe($stages->where('current_stage', 'passport')->count())
            ->and($totals['flight_done'])->toBe($stages->where('current_stage', 'completed')->count())
            ->and($totals['medical_fit'] + $totals['medical_unfit'] + $totals['medical_pending'] + $totals['not_started'])->toBe($totals['total'])
            ->and($totals['in_process'])->toBe($totals['total'] - $totals['flight_done'] - $totals['medical_unfit']);
    });

    it('exports UTF-8 CSV with a BOM, the date in the file name and a totals row', function () {
        ['a' => $a] = reportCompanies();
        $a->forceFill(['name' => '=HYPERLINK("x")'])->save();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->get('/api/reports/companies?export=csv&sort=name')->assertOk();
        $body = $response->streamedContent();
        $rows = csvRows($body);

        expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and($response->headers->get('Content-Type'))->toContain('text/csv')
            // 1 May 2026 in Dhaka (still 30 April in UTC).
            ->and($response->headers->get('Content-Disposition'))->toContain('company-report-01-05-2026.csv')
            ->and($rows[0])->toBe(['ID', 'Company', 'Country', 'Quota', 'Total', 'Medical fit', 'Medical unfit', 'Medical pending', 'Not started', 'Calling done', 'Visa done', 'Bmet done', 'Flight done', 'In process', 'Balance'])
            ->and($rows)->toHaveCount(5)
            ->and($rows[1])->toBe([(string) $a->id, '\'=HYPERLINK("x")', 'MY', '10', '8', '5', '2', '1', '0', '3', '2', '2', '1', '5', '2'])
            ->and($rows[2][14])->toBe('')
            ->and($rows[4])->toBe(['Total', '', '', '13', '9', '5', '2', '2', '0', '3', '2', '2', '1', '6', '5']);
    });
});

describe('company passports', function () {
    it('lists the company\'s passports, unfit included, with latest status and the six steps', function () {
        ['a' => $a, 'b' => $b] = reportCompanies();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $response = $this->getJson("/api/reports/companies/{$a->id}/passports?per_page=100")->assertOk();

        expect($response->json('meta.total'))->toBe(8)
            ->and(collect($response->json('data'))->pluck('company.id')->unique()->all())->toBe([$a->id])
            ->and(collect($response->json('data.*.latest_status.text'))->countBy()->all())->toMatchArray([
                'Medical: Unfit' => 2,
                'Medical: Pending' => 1,
                'Flight confirmed (30-04-2026)' => 1,
                'BMET cleared - flight booking date pending' => 1,
            ])
            ->and($response->json('data.0.workflow.steps'))->toHaveCount(6)
            ->and($response->json('data.0'))->toHaveKeys(['current_stage', 'stage_status', 'medical_status', 'current_medical', 'warnings'])
            // Read only: no action permissions, only whether passport details may be edited.
            ->and($response->json('data.0'))->not->toHaveKey('can')
            ->and($response->json('data.*.can_edit_passport'))->each->toBeFalse();

        $this->getJson("/api/reports/companies/{$a->id}/passports?stage=medical&stage_status=unfit")
            ->assertJsonPath('meta.total', 2);
        $this->getJson("/api/reports/companies/{$a->id}/passports?per_page=3&page=3")
            ->assertJsonCount(2, 'data');
        $this->getJson("/api/reports/companies/{$b->id}/passports")
            ->assertJsonPath('meta.total', 1);
    });

    it('runs a fixed number of queries however many passports are listed', function () {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $countQueries = function () use ($company): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/reports/companies/{$company->id}/passports?per_page=100")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (['completed', 'unfit'] as $state) {
            companyPassport($company, $state);
        }
        $few = $countQueries();

        foreach (['completed', 'unfit', 'fit', 'visa_waiting', 'pending', 'calling_in_process'] as $state) {
            companyPassport($company, $state);
        }

        expect($countQueries())->toBe($few);
    });
});

describe('access', function () {
    it('lets every staff role read the reports', function (string $role) {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/reports/companies')->assertOk();
        $this->getJson("/api/reports/companies/{$company->id}/passports")->assertOk();
        $this->get('/api/reports/companies?export=csv')->assertOk();
    })->with(['dataEntry', 'accounts', 'admin', 'superAdmin']);

    it('rejects unauthenticated requests', function () {
        $company = Company::factory()->create();

        $this->getJson('/api/reports/companies')->assertUnauthorized();
        $this->getJson("/api/reports/companies/{$company->id}/passports")->assertUnauthorized();
    });

    it('is read only for accounts', function () {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->postJson('/api/reports/companies', [])->assertMethodNotAllowed();
        $this->patchJson("/api/data-entry/companies/{$company->id}", ['quota' => 1])->assertForbidden();
        $this->postJson('/api/data-entry/companies', ['name' => 'X', 'country_code' => 'MY'])->assertForbidden();
    });
});
