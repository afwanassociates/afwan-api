<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\MedicalService;
use Database\Seeders\MedicalDataSeeder;
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
 * A passport with a current medical in the given state ("pending" for none).
 *
 * @param  array<string, mixed>  $attributes
 */
function passportWithMedical(string $state, array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->create([
        'passport_received_date' => '2026-01-01',
        'passport_expiry_date' => '2035-01-01',
        ...$attributes,
    ]);

    $factory = MedicalRecord::factory()->for($passport);

    match ($state) {
        'pending' => null,
        'fit' => $factory->validUntil('2026-06-30')->create(),
        'expiring_soon' => $factory->validUntil('2026-05-10')->create(),
        'expired' => $factory->validUntil('2026-04-20')->create(),
        'unfit' => $factory->unfit()->on('2026-04-20')->create(),
    };

    return $passport->fresh();
}

/**
 * IDs returned by the passport list for the given query string.
 *
 * @return list<int>
 */
function listedPassportIds(string $query = ''): array
{
    return test()->getJson("/api/data-entry/passports?per_page=100&{$query}")->assertOk()->json('data.*.id');
}

describe('medical status', function () {
    it('works out the status at the boundaries using the Dhaka date', function (string $validUntil, string $status, int $daysLeft) {
        $passport = PassportEntry::factory()->create();
        MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertOk()
            ->assertJsonPath('data.medical_status', $status)
            ->assertJsonPath('data.current_medical.valid_until', $validUntil)
            ->assertJsonPath('data.current_medical.days_left', $daysLeft);
    })->with([
        // Valid until today is still fit (inside the expiring-soon window).
        'valid until today' => ['2026-05-01', 'expiring_soon', 0],
        // Yesterday in Dhaka, although it is still "today" in UTC.
        'valid until yesterday' => ['2026-04-30', 'expired', -1],
        'exactly 14 days left' => ['2026-05-15', 'expiring_soon', 14],
        '15 days left' => ['2026-05-16', 'fit', 15],
    ]);

    it('counts a passport valid until today as fit, not expired', function () {
        $passport = PassportEntry::factory()->create();
        MedicalRecord::factory()->for($passport)->validUntil('2026-05-01')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds('medical_status=fit'))->toBe([$passport->id])
            ->and(listedPassportIds('medical_status=expired'))->toBe([]);
    });

    it('shows pending, with no current medical, when there is no record', function () {
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.medical_status', 'pending')
            ->assertJsonPath('data.current_medical', null);
    });

    it('returns the current medical with who recorded it', function () {
        $clerk = User::factory()->dataEntry()->create(['name' => 'Rafi']);
        $passport = PassportEntry::factory()->create();
        $record = MedicalRecord::factory()->for($passport)->createdBy($clerk)->on('2026-04-20')->create(['remarks' => 'All clear']);
        Sanctum::actingAs($clerk);

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.current_medical.id', $record->id)
            ->assertJsonPath('data.current_medical.medical_date', '2026-04-20')
            ->assertJsonPath('data.current_medical.result', 'fit')
            ->assertJsonPath('data.current_medical.remarks', 'All clear')
            ->assertJsonPath('data.current_medical.recorded_by', ['id' => $clerk->id, 'name' => 'Rafi']);
    });
});

describe('passport list', function () {
    it('leaves unfit passports out of the default list and search, and lists them on their own', function () {
        $fit = passportWithMedical('fit');
        $pending = passportWithMedical('pending');
        $unfit = passportWithMedical('unfit', ['passport_number' => 'UF1234567']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds())->toEqualCanonicalizing([$fit->id, $pending->id])
            ->and(listedPassportIds('q=UF1234567'))->toBe([])
            ->and(listedPassportIds('medical_status=unfit'))->toBe([$unfit->id])
            ->and(listedPassportIds('medical_status=unfit&q=UF1234567'))->toBe([$unfit->id]);
    });

    it('brings an unfit passport back to the default list after an admin records a fit re-test', function () {
        $unfit = passportWithMedical('unfit');
        Sanctum::actingAs(User::factory()->admin()->create());

        expect(listedPassportIds())->toBe([]);

        $this->postJson("/api/data-entry/passports/{$unfit->id}/medicals", ['medical_date' => '2026-04-28', 'result' => 'fit'])
            ->assertCreated();

        expect(listedPassportIds())->toBe([$unfit->id])
            ->and(listedPassportIds('medical_status=unfit'))->toBe([]);
    });

    it('filters by each medical status', function () {
        $passports = collect(['pending', 'fit', 'expiring_soon', 'expired', 'unfit'])
            ->mapWithKeys(fn (string $state) => [$state => passportWithMedical($state)->id]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds('medical_status=pending'))->toBe([$passports['pending']])
            ->and(listedPassportIds('medical_status=fit'))->toEqualCanonicalizing([$passports['fit'], $passports['expiring_soon']])
            ->and(listedPassportIds('medical_status=expiring_soon'))->toBe([$passports['expiring_soon']])
            ->and(listedPassportIds('medical_status=expired'))->toBe([$passports['expired']])
            ->and(listedPassportIds('medical_status=unfit'))->toBe([$passports['unfit']]);
    });

    it('combines the medical filter with the existing filters', function () {
        $match = passportWithMedical('fit');
        passportWithMedical('fit');
        passportWithMedical('pending', ['company_id' => $match->company_id]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds("medical_status=fit&company_id={$match->company_id}"))->toBe([$match->id])
            ->and(listedPassportIds("company_id={$match->company_id}&received_from=2026-01-01&received_to=2026-01-01"))->toHaveCount(2);
    });

    it('sorts by medical date and valid until', function () {
        $late = passportWithMedical('fit');
        $early = passportWithMedical('expired');
        $middle = passportWithMedical('expiring_soon');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds('sort=valid_until&direction=asc'))->toBe([$early->id, $middle->id, $late->id])
            ->and(listedPassportIds('sort=medical_date&direction=desc'))->toBe([$late->id, $middle->id, $early->id]);
    });

    it('rejects an unknown medical status', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/passports?medical_status=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_status');
    });

    it('runs a fixed number of queries however many passports are listed', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/data-entry/passports?per_page=100')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (['fit', 'expired', 'pending'] as $state) {
            passportWithMedical($state);
        }
        $few = $countQueries();

        foreach (['fit', 'expiring_soon', 'expired', 'pending', 'fit', 'fit', 'expired', 'pending'] as $state) {
            passportWithMedical($state);
        }

        // count + page + country + reference + company + company country
        // + current medical + its creator.
        expect($countQueries())->toBe($few)->toBe(9);
    });
});

describe('workflow', function () {
    it('describes each step for the step bar', function (string $state, array $expected, ?string $currentStep) {
        $passport = passportWithMedical($state);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson("/api/data-entry/passports/{$passport->id}")->assertOk();

        expect($response->json('data.workflow.steps.*.state'))->toBe($expected)
            ->and($response->json('data.workflow.current_step'))->toBe($currentStep)
            ->and($response->json('data.workflow.steps.*.key'))->toBe(['passport', 'medical', 'calling', 'visa', 'bmet', 'flight'])
            ->and($response->json('data.workflow.steps.2'))->toMatchArray(['label' => 'Calling / Work Permit', 'enabled' => true, 'record' => null]);
    })->with([
        'pending' => ['pending', ['done', 'pending', 'locked', 'locked', 'locked', 'locked'], 'medical'],
        'fit' => ['fit', ['done', 'passed', 'waiting', 'locked', 'locked', 'locked'], 'calling'],
        'expiring soon' => ['expiring_soon', ['done', 'passed', 'waiting', 'locked', 'locked', 'locked'], 'calling'],
        // A fit medical whose validity ended still lets the workflow move on.
        'expired' => ['expired', ['done', 'expired', 'waiting', 'locked', 'locked', 'locked'], 'calling'],
        'unfit' => ['unfit', ['done', 'failed', 'locked', 'locked', 'locked', 'locked'], 'medical'],
    ]);

    it('flags an incomplete passport as needing attention', function () {
        $passport = passportWithMedical('fit', ['date_of_birth' => null]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.workflow.steps.0.state', 'needs_attention')
            ->assertJsonPath('data.workflow.current_step', 'passport');
    });

    it('summarises the counts for the step bar', function () {
        passportWithMedical('pending');
        passportWithMedical('pending', ['country_code' => null]);
        passportWithMedical('fit');
        passportWithMedical('expiring_soon');
        passportWithMedical('expired');
        passportWithMedical('unfit');
        passportWithMedical('unfit', ['passport_expiry_date' => null]);
        passportWithMedical('fit')->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        DB::enableQueryLog();
        $response = $this->getJson('/api/data-entry/workflow/summary')->assertOk();

        expect(DB::getQueryLog())->toHaveCount(2);
        $response
            ->assertJsonPath('data.step1', ['total_all' => 7, 'total_active' => 5, 'incomplete' => 1])
            ->assertJsonPath('data.step2', ['pending' => 2, 'fit' => 2, 'expiring_soon' => 1, 'expired' => 1, 'unfit' => 2])
            // Fit, expiring soon and expired medicals all wait for calling.
            ->assertJsonPath('data.step3', ['enabled' => true, 'ready' => 3])
            ->assertJsonPath('data.steps.*.key', ['passport', 'medical', 'calling', 'visa', 'bmet', 'flight']);
    });

    it('returns zero counts when there are no passports', function () {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/data-entry/workflow/summary')
            ->assertOk()
            ->assertJsonPath('data.step1', ['total_all' => 0, 'total_active' => 0, 'incomplete' => 0])
            ->assertJsonPath('data.step2', ['pending' => 0, 'fit' => 0, 'expiring_soon' => 0, 'expired' => 0, 'unfit' => 0]);
    });
});

describe('medical queue', function () {
    it('lists pending passports oldest received first, with only the needed fields', function () {
        $newer = passportWithMedical('pending', ['passport_received_date' => '2026-03-01']);
        $older = passportWithMedical('pending', ['passport_received_date' => '2026-02-01']);
        passportWithMedical('fit');
        passportWithMedical('unfit');
        passportWithMedical('pending')->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson('/api/data-entry/medical/queue?per_page=1')->assertOk();

        $response->assertExactJson([
            'data' => [[
                'id' => $older->id,
                'passport_name' => $older->passport_name,
                'passport_number' => $older->passport_number,
                'passport_received_date' => '2026-02-01',
            ]],
            'meta' => [
                'per_page' => 1,
                'next_cursor' => $response->json('meta.next_cursor'),
                'prev_cursor' => null,
            ],
        ]);

        $this->getJson('/api/data-entry/medical/queue?per_page=1&cursor='.$response->json('meta.next_cursor'))
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('meta.next_cursor', null);
    });
});

describe('duplicate passport numbers', function () {
    it('says which list the existing entry is in', function (string $state, string $location) {
        passportWithMedical($state, ['passport_number' => 'AB1234567']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', [
            'passport_name' => 'Md Karim Ali',
            'passport_number' => 'ab1234567',
            'date_of_birth' => '1995-04-12',
            'reference_id' => PassportEntry::first()->reference_id,
            'company_id' => PassportEntry::first()->company_id,
            'passport_received_date' => '2026-04-01',
            'passport_expiry_date' => '2031-04-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['passport_number' => "This passport number is already entered ({$location})."]);
    })->with([
        'unfit' => ['unfit', 'it is in the Unfit list'],
        'pending' => ['pending', 'it is in the Passport list'],
        'expired medical' => ['expired', 'it is in the Passport list'],
    ]);
});

describe('development seeder', function () {
    it('creates medical records covering every status', function () {
        $this->seed();

        $statuses = PassportEntry::query()->with('currentMedical')->get()
            ->map(fn (PassportEntry $passport) => $passport->medicalStatus()->value)
            ->unique()->sort()->values()->all();

        expect($statuses)->toBe(['expired', 'expiring_soon', 'fit', 'pending', 'unfit'])
            ->and(MedicalRecord::query()->whereNull('valid_until')->where('result', 'fit')->exists())->toBeFalse();

        // Running it again adds nothing.
        $count = MedicalRecord::count();
        $this->seed();
        expect(MedicalRecord::count())->toBe($count);
    });

    it('refuses to run in production', function () {
        app()->detectEnvironment(fn () => 'production');

        PassportEntry::factory()->create();

        app(MedicalDataSeeder::class)->run(app(MedicalService::class));

        expect(MedicalRecord::count())->toBe(0);
    });
});

describe('all passports', function () {
    it('lists every medical status with medical_status=all, while the default still leaves unfit out', function () {
        $passports = collect(['pending', 'fit', 'expiring_soon', 'expired', 'unfit'])
            ->mapWithKeys(fn (string $state) => [$state => passportWithMedical($state)->id]);
        passportWithMedical('unfit')->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds('medical_status=all'))->toEqualCanonicalizing($passports->values()->all())
            ->and(listedPassportIds())->toEqualCanonicalizing($passports->except('unfit')->values()->all())
            ->and(listedPassportIds('medical_status=unfit'))->toBe([$passports['unfit']]);
    });

    it('shows unfit passports in the all list to data entry, admin and super admin', function (string $role) {
        $unfit = passportWithMedical('unfit');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/data-entry/passports?medical_status=all')
            ->assertOk()
            ->assertJsonPath('data.0.id', $unfit->id)
            ->assertJsonPath('data.0.medical_status', 'unfit')
            ->assertJsonPath('data.0.workflow.steps.1.state', 'failed');
    })->with(['dataEntry', 'admin', 'superAdmin']);

    it('combines with search, company, reference and date filters', function () {
        $unfit = passportWithMedical('unfit', ['passport_number' => 'UF1234567', 'passport_received_date' => '2026-02-10']);
        $fit = passportWithMedical('fit', ['company_id' => $unfit->company_id, 'passport_received_date' => '2026-02-20']);
        $other = passportWithMedical('expired', ['passport_received_date' => '2026-03-15']);
        $companyCountry = $unfit->company->country_code;
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(listedPassportIds('medical_status=all&q=UF1234567'))->toBe([$unfit->id])
            ->and(listedPassportIds("medical_status=all&company_id={$unfit->company_id}"))->toEqualCanonicalizing([$unfit->id, $fit->id])
            ->and(listedPassportIds("medical_status=all&reference_id={$other->reference_id}"))->toBe([$other->id])
            ->and(listedPassportIds("medical_status=all&company_country_code={$companyCountry}"))->toContain($unfit->id)
            ->and(listedPassportIds('medical_status=all&received_from=2026-02-01&received_to=2026-02-28'))->toEqualCanonicalizing([$unfit->id, $fit->id]);
    });

    it('sorts and paginates', function () {
        $fit = passportWithMedical('fit');
        $expired = passportWithMedical('expired');
        $unfit = passportWithMedical('unfit');
        $pending = passportWithMedical('pending');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // Medical dates: expired 01-20, fit 03-30, unfit 04-20; pending has none (sorts first).
        expect(listedPassportIds('medical_status=all&sort=medical_date&direction=asc'))
            ->toBe([$pending->id, $expired->id, $fit->id, $unfit->id]);

        $this->getJson('/api/data-entry/passports?medical_status=all&sort=medical_date&direction=desc&per_page=2&page=1')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.*.id', [$unfit->id, $fit->id]);
        $this->getJson('/api/data-entry/passports?medical_status=all&sort=medical_date&direction=desc&per_page=2&page=2')
            ->assertJsonPath('data.*.id', [$expired->id, $pending->id]);
    });

    it('runs a fixed number of queries however many passports are listed', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/data-entry/passports?medical_status=all&per_page=100')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (['fit', 'unfit', 'pending'] as $state) {
            passportWithMedical($state);
        }
        $few = $countQueries();

        foreach (['unfit', 'expiring_soon', 'expired', 'pending', 'fit', 'unfit', 'expired', 'pending'] as $state) {
            passportWithMedical($state);
        }

        expect($countQueries())->toBe($few)->toBe(9);
    });

    it('rejects an unknown medical status', function (string $value) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports?medical_status={$value}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_status');
    })->with(['ALL', 'everything', 'fit,unfit']);

    it('rejects the accounts role and unauthenticated requests', function () {
        $this->getJson('/api/data-entry/passports?medical_status=all')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/passports?medical_status=all')->assertForbidden();
    });

    it('adds total_all to the summary as the sum of every status, without soft-deleted passports', function () {
        foreach (['pending', 'pending', 'fit', 'expiring_soon', 'expired', 'unfit', 'unfit', 'unfit'] as $state) {
            passportWithMedical($state);
        }
        passportWithMedical('unfit')->delete();
        passportWithMedical('pending')->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        DB::enableQueryLog();
        $response = $this->getJson('/api/data-entry/workflow/summary')->assertOk();
        expect(DB::getQueryLog())->toHaveCount(2);

        $step2 = $response->json('data.step2');
        // fit already includes expiring_soon, so it is not added twice.
        $everyStatus = $step2['pending'] + $step2['fit'] + $step2['expired'] + $step2['unfit'];

        expect($response->json('data.step1'))->toBe(['total_all' => 8, 'total_active' => 5, 'incomplete' => 0])
            ->and($everyStatus)->toBe(8)
            ->and($step2)->toBe(['pending' => 2, 'fit' => 2, 'expiring_soon' => 1, 'expired' => 1, 'unfit' => 3]);
    });
});
