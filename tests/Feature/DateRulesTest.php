<?php

use App\Enums\MedicalResult;
use App\Enums\MedicalStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\MedicalService;
use App\Support\DateRules;
use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow('2026-10-25 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** Every rule (the built-in defaults) with $overrides, as a country's full set. */
function fullDateRules(array $overrides = []): array
{
    return [...app(DateRules::class)->values(), ...$overrides];
}

/** Give a country a full set of rules. */
function setCountryRules(string $country, array $overrides = []): void
{
    app(DateRules::class)->save(fullDateRules($overrides), User::factory()->superAdmin()->create(), $country);
}

/** The rules page for a country as key => value. */
function countryRuleValues(string $country): array
{
    return collect(test()->getJson("/api/super-admin/date-rules?country={$country}")->assertOk()->json('data'))
        ->pluck('value', 'key')->all();
}

describe('date rules', function () {
    it('is for the super admin only', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/super-admin/date-rules')->assertForbidden();
        $this->putJson('/api/super-admin/date-rules', fullDateRules(['country' => 'MY']))->assertForbidden();
        $this->deleteJson('/api/super-admin/date-rules/countries/MY')->assertForbidden();
        expect(app(DateRules::class)->countries())->toBe([]);
    })->with(['admin', 'accountsHead', 'accounts', 'dataEntry']);

    it('lists every rule with its unit and limits, empty for a country without rules', function () {
        Company::factory()->count(2)->create(['country_code' => 'MY']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $response = $this->getJson('/api/super-admin/date-rules?country=SG')->assertOk();

        expect($response->json('data.*.key'))->toBe([
            'medical_validity_days', 'medical_expiring_soon_days',
            'calling_validity_months', 'visa_validity_months',
            'calling_warning_days', 'visa_warning_days', 'bmet_warning_days', 'flight_warning_days',
            'passport_min_validity_months', 'passport_expiry_warning_months', 'minimum_age_years',
        ])
            ->and($response->json('data.0'))->toMatchArray(['group' => 'Medical', 'unit' => 'days', 'min' => 1, 'max' => 730, 'value' => null])
            ->and($response->json('data.0'))->not->toHaveKey('default')
            ->and($response->json('meta.country'))->toBe(['code' => 'SG', 'name' => 'Singapore'])
            ->and($response->json('meta.countries'))->toBe([])
            // Malaysian companies, but no rules for Malaysia yet.
            ->and($response->json('meta.missing'))->toBe([['code' => 'MY', 'name' => 'Malaysia', 'companies' => 2]]);
    });

    it('a new country must have every rule; then it can change one at a time', function () {
        Company::factory()->create(['country_code' => 'MY']);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/super-admin/date-rules', ['country' => 'MY', 'medical_validity_days' => 90])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minimum_age_years' => 'Enter Minimum age (years).']);
        expect(app(DateRules::class)->countries())->toBe([]);

        $this->putJson('/api/super-admin/date-rules', fullDateRules(['country' => 'MY', 'medical_validity_days' => 100]))
            ->assertOk()
            ->assertJsonPath('data.0.value', 100)
            ->assertJsonPath('meta.countries', [['code' => 'MY', 'name' => 'Malaysia', 'complete' => true, 'companies' => 1]])
            ->assertJsonPath('meta.missing', []);

        $this->putJson('/api/super-admin/date-rules', ['country' => 'MY', 'visa_warning_days' => 45])->assertOk();
        expect(countryRuleValues('MY'))->toMatchArray(['medical_validity_days' => 100, 'visa_warning_days' => 45]);
    });

    it('needs a known country, and values within the limits', function () {
        setCountryRules('MY');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/super-admin/date-rules', ['medical_validity_days' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors(['country' => 'Choose the country these rules are for.']);
        $this->putJson('/api/super-admin/date-rules', fullDateRules(['country' => 'XX']))
            ->assertUnprocessable()->assertJsonValidationErrors(['country' => 'That country does not exist.']);
        $this->putJson('/api/super-admin/date-rules', ['country' => 'MY', 'medical_validity_days' => 0, 'visa_warning_days' => 40])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_validity_days' => 'Fit result validity must be at least 1 days.']);
        $this->putJson('/api/super-admin/date-rules', ['country' => 'MY', 'minimum_age_years' => 101])
            ->assertUnprocessable()->assertJsonValidationErrors('minimum_age_years');
        $this->putJson('/api/super-admin/date-rules', ['country' => 'MY', 'passport_min_validity_months' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('passport_min_validity_months');

        expect(countryRuleValues('MY'))->toMatchArray(['medical_validity_days' => 90, 'visa_warning_days' => 30]);
    });

    it('removes a country\'s rules', function () {
        setCountryRules('SG', ['minimum_age_years' => 21]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->deleteJson('/api/super-admin/date-rules/countries/SG')->assertNoContent();

        expect(app(DateRules::class)->countries())->toBe([])
            ->and(countryRuleValues('SG')['minimum_age_years'])->toBeNull()
            // Its passports fall back to the built-in defaults.
            ->and(app(DateRules::class)->get(DateRules::MINIMUM_AGE_YEARS, 'SG'))->toBe(18);
    });

    it('sends each country\'s rules and the built-in fallback to the forms', function () {
        setCountryRules('MY', ['calling_validity_months' => 4]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/defaults')
            ->assertOk()
            ->assertJsonPath('data.date_rules.calling_validity_months', 3)
            ->assertJsonPath('data.date_rules_by_country.MY.calling_validity_months', 4)
            ->assertJsonPath('data.date_rules_by_country.MY.minimum_age_years', 18)
            ->assertJsonMissingPath('data.date_rules_by_country.SG');
    });

    it('sets the default rules for a country with the command, keeping the ones it has', function () {
        $rules = app(DateRules::class);
        $rules->save([DateRules::MINIMUM_AGE_YEARS => 20], null, 'MY');

        $this->artisan('app:copy-date-rules-to-country')
            ->expectsOutputToContain('10 date rule(s) set for Malaysia (MY).')
            ->assertSuccessful();

        expect($rules->ownValue(DateRules::MEDICAL_VALIDITY_DAYS, 'MY'))->toBe(90)
            ->and($rules->ownValue(DateRules::MINIMUM_AGE_YEARS, 'MY'))->toBe(20)
            ->and($rules->isComplete('MY'))->toBeTrue();

        $this->artisan('app:copy-date-rules-to-country MY')->expectsOutputToContain('Nothing changed')->assertSuccessful();
        $this->artisan('app:copy-date-rules-to-country XX')->assertFailed();
    });

    describe('by the passport\'s company country', function () {
        it('applies the medical validity and "expiring soon" days', function () {
            setCountryRules('MY', ['medical_validity_days' => 30, 'medical_expiring_soon_days' => 20]);

            expect(MedicalService::validUntil('2026-10-01', MedicalResult::Fit, 'MY'))->toBe('2026-10-31')
                ->and(MedicalService::validUntil('2026-10-01', MedicalResult::Fit, 'SG'))->toBe('2026-12-30')
                ->and(MedicalStatus::expiringSoonLimit(LocalDate::today(), 'MY')->toDateString())->toBe('2026-11-14');
        });

        it('lists and counts "expiring soon" by each passport\'s company country', function () {
            setCountryRules('SG', ['medical_expiring_soon_days' => 30]);
            setCountryRules('MY');
            $passport = function (string $country) {
                $company = Company::factory()->create(['country_code' => $country]);
                $entry = PassportEntry::factory()->create(['company_id' => $company->id, 'medical_slip_date' => '2026-10-01']);
                // 20 days left: expiring soon in Singapore (30), still fit in Malaysia (14).
                $record = MedicalRecord::factory()->for($entry)->validUntil('2026-11-14')->create();
                $entry->update(['current_medical_id' => $record->id]);

                return $entry->fresh();
            };
            $sg = $passport('SG');
            $my = $passport('MY');
            Sanctum::actingAs(User::factory()->dataEntry()->create());

            $ids = fn (string $status) => collect($this->getJson("/api/data-entry/passports?mode=medical&medical_status={$status}")->assertOk()->json('data'))->pluck('id')->all();

            expect($ids('expiring_soon'))->toBe([$sg->id])
                ->and($ids('fit'))->toContain($my->id)
                ->and($sg->medicalStatus())->toBe(MedicalStatus::ExpiringSoon)
                ->and($my->medicalStatus())->toBe(MedicalStatus::Fit)
                ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.step2.expiring_soon'))->toBe(1);
        });

        it('warns about step expiry dates', function () {
            setCountryRules('SG', ['calling_warning_days' => 45]);
            setCountryRules('MY');
            $record = function (string $country) {
                $entry = PassportEntry::factory()->create(['company_id' => Company::factory()->create(['country_code' => $country])->id]);

                return PassportStepRecord::factory()->for($entry)->step('calling')->create(['valid_until' => '2026-12-04']); // 40 days left
            };

            expect(app(Workflow::class)->validityStatus($record('SG')))->toBe('expiring_soon')
                ->and(app(Workflow::class)->validityStatus($record('MY')))->toBe('valid');
        });

        it('checks a new passport\'s minimum validity and minimum age', function () {
            setCountryRules('SG', ['passport_min_validity_months' => 24, 'minimum_age_years' => 21]);
            setCountryRules('MY');
            Sanctum::actingAs(User::factory()->dataEntry()->create());
            $singapore = fn () => Company::factory()->create(['country_code' => 'SG'])->id;

            $this->postJson('/api/data-entry/passports', passportPayload([
                'company_id' => $singapore(),
                'passport_received_date' => '2026-10-24',
                'passport_expiry_date' => '2028-10-23', // one day short of 24 months
                'date_of_birth' => '2005-10-26', // one day short of 21 years
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'passport_expiry_date' => 'The passport must be valid for at least 24 months after the received date.',
                    'date_of_birth' => 'The person must be at least 21 years old.',
                ]);

            $this->postJson('/api/data-entry/passports', passportPayload([
                'company_id' => $singapore(),
                'passport_received_date' => '2026-10-24',
                'passport_expiry_date' => '2028-10-24',
                'date_of_birth' => '2005-10-25',
            ]))->assertCreated();

            // Malaysia: 18 years.
            $this->postJson('/api/data-entry/passports', passportPayload([
                'company_id' => Company::factory()->create(['country_code' => 'MY'])->id,
                'passport_number' => 'MY1234567',
                'date_of_birth' => '2007-10-01',
            ]))->assertCreated();
        });

        it('has no age check when a country\'s minimum age is 0', function () {
            setCountryRules('MY', ['minimum_age_years' => 0]);
            Sanctum::actingAs(User::factory()->dataEntry()->create());

            $this->postJson('/api/data-entry/passports', passportPayload([
                'company_id' => Company::factory()->create(['country_code' => 'MY'])->id,
                'date_of_birth' => '2020-01-01',
            ]))->assertCreated();
        });

        it('runs a fixed number of queries however many passports are listed', function () {
            setCountryRules('MY');
            Sanctum::actingAs(User::factory()->dataEntry()->create());
            $add = function (int $count) {
                foreach (range(1, $count) as $i) {
                    $entry = PassportEntry::factory()->create(['company_id' => Company::factory()->create()->id, 'medical_slip_date' => '2026-10-01']);
                    $record = MedicalRecord::factory()->for($entry)->validUntil('2026-11-30')->create();
                    $entry->update(['current_medical_id' => $record->id]);
                    PassportStepRecord::factory()->for($entry)->step('calling')->create(['valid_until' => '2026-12-04']);
                }
            };
            $queries = function (string $url): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->getJson($url)->assertOk();
                DB::disableQueryLog();

                return count(DB::getQueryLog());
            };
            $urls = [
                '/api/data-entry/passports?medical_status=all&per_page=100',
                '/api/data-entry/passports?mode=medical&medical_status=fit&per_page=100',
                '/api/data-entry/steps/calling/passports?tab=permitted&per_page=100',
            ];

            $add(2);
            $few = array_map($queries, $urls);
            $add(5);

            expect(array_map($queries, $urls))->toBe($few);
        });
    });
});
