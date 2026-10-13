<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

describe('migration data', function () {
    it('creates every country from config/countries.php, all active, with Bangladesh and Malaysia pinned', function () {
        expect(Country::count())->toBe(count(config('countries')))
            ->and(Country::where('is_active', false)->exists())->toBeFalse()
            ->and(Country::where('is_pinned', true)->orderBy('sort_order')->pluck('name', 'code')->all())
            ->toBe(['BD' => 'Bangladesh', 'MY' => 'Malaysia']);
    });

    it('seeds the settings', function () {
        // The passport default is filled with MY by 2026_10_15_000001.
        expect(AppSetting::pluck('value', 'key')->all())->toBe([
            'default_company_country_code' => 'MY',
            'default_passport_country_code' => 'MY',
        ]);
    });

    it('gives existing companies the default company country (Malaysia)', function () {
        $migration = require database_path('migrations/2026_10_05_000004_add_country_code_to_companies_table.php');

        // Schema changes end MySQL transactions, so leave the test transaction,
        // clean up by hand, and open a new one for RefreshDatabase to roll back.
        DB::rollBack();

        try {
            $migration->down();
            DB::table('companies')->insert(['name' => 'Company Before Countries', 'created_at' => now(), 'updated_at' => now()]);
            $migration->up();

            expect(DB::table('companies')->where('name', 'Company Before Countries')->value('country_code'))->toBe('MY');
        } finally {
            DB::table('companies')->where('name', 'Company Before Countries')->delete();
            DB::beginTransaction();
        }
    });
});

describe('data-entry lookups', function () {
    beforeEach(fn () => Sanctum::actingAs(User::factory()->dataEntry()->create()));

    it('lists active countries, pinned first, then the rest by name, with cache headers', function () {
        Country::whereKey('FR')->update(['is_active' => false]);

        $response = $this->getJson('/api/data-entry/countries')->assertOk();
        $codes = collect($response->json('data'))->pluck('code');

        expect($response->headers->get('Cache-Control'))->toContain('max-age=300')->toContain('private')
            ->and($codes->take(2)->all())->toBe(['BD', 'MY'])
            ->and($codes)->not->toContain('FR')
            ->and($codes)->toHaveCount(Country::count() - 1)
            ->and($response->json('data.0'))->toBe(['code' => 'BD', 'name' => 'Bangladesh', 'is_pinned' => true])
            ->and(collect($response->json('data'))->skip(2)->pluck('name')->all())
            ->toBe(Country::active()->where('is_pinned', false)->orderBy('name')->pluck('name')->all());
    });

    it('returns the defaults', function () {
        app(Settings::class)->set(Settings::DEFAULT_PASSPORT_COUNTRY, 'BD');

        $this->getJson('/api/data-entry/defaults')
            ->assertOk()
            ->assertExactJson(['data' => [
                'default_company_country' => ['code' => 'MY', 'name' => 'Malaysia'],
                'default_passport_country' => ['code' => 'BD', 'name' => 'Bangladesh'],
                'default_company_country_code' => 'MY',
                'default_passport_country_code' => 'BD',
                'medical_validity_months' => 3,
                'medical_expiring_soon_days' => 14,
            ]]);
    });

    it('keeps showing the names of deactivated countries on old records', function () {
        $company = Company::factory()->create(['country_code' => 'QA']);
        $entry = PassportEntry::factory()->create(['country_code' => 'NP', 'company_id' => $company->id]);
        Country::whereKey(['NP', 'QA'])->update(['is_active' => false]);

        $this->getJson("/api/data-entry/passports/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.country', ['code' => 'NP', 'name' => 'Nepal'])
            ->assertJsonPath('data.company.country', ['code' => 'QA', 'name' => 'Qatar']);

        $this->getJson('/api/data-entry/companies?q='.urlencode($company->name))
            ->assertOk()
            ->assertJsonPath('data.0.country', ['code' => 'QA', 'name' => 'Qatar']);

        $this->getJson('/api/data-entry/countries')->assertOk()->assertJsonMissing(['code' => 'NP']);
    });

    it('rejects the accounts role and guests', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/countries')->assertForbidden();
        $this->getJson('/api/data-entry/defaults')->assertForbidden();

        app('auth')->forgetGuards();
        $this->getJson('/api/data-entry/countries')->assertUnauthorized();
        $this->getJson('/api/data-entry/defaults')->assertUnauthorized();
    });
});

describe('company country', function () {
    it('requires an active country when creating a company', function (array $payload) {
        Country::whereKey('FR')->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/companies', ['name' => 'New Employer', 'quota' => 10, ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');
    })->with([
        'missing' => [[]],
        'inactive' => [['country_code' => 'FR']],
        'unknown' => [['country_code' => 'QQ']],
        'too long' => [['country_code' => 'MYS']],
    ]);

    it('creates a company with its country, accepting the code in any case', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/companies', ['name' => 'Riyadh Builders', 'quota' => 10, 'country_code' => ' sa '])
            ->assertCreated()
            ->assertJsonPath('data.country', ['code' => 'SA', 'name' => 'Saudi Arabia']);

        $this->getJson('/api/data-entry/companies?q=riyadh')
            ->assertOk()
            ->assertJsonPath('data.0.country', ['code' => 'SA', 'name' => 'Saudi Arabia']);
    });

    it('lets admins keep a company\'s deactivated country but not switch to another inactive one', function () {
        $company = Company::factory()->create(['country_code' => 'QA']);
        Country::whereKey(['QA', 'FR'])->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/data-entry/companies/{$company->id}", ['name' => 'Renamed', 'country_code' => 'QA'])->assertOk();
        $this->patchJson("/api/data-entry/companies/{$company->id}", ['country_code' => 'FR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');
        $this->patchJson("/api/data-entry/companies/{$company->id}", ['country_code' => 'AE'])
            ->assertOk()
            ->assertJsonPath('data.country.code', 'AE');
    });
});

describe('passport entries', function () {
    it('rejects an inactive passport country on new entries but keeps it on edit', function () {
        $clerk = User::factory()->dataEntry()->create();
        $entry = PassportEntry::factory()->createdBy($clerk)->create(['country_code' => 'NP']);
        Country::whereKey(['NP', 'FR'])->update(['is_active' => false]);
        Sanctum::actingAs($clerk);

        $this->postJson('/api/data-entry/passports', passportPayload(['country_code' => 'NP']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_name' => 'Edited', 'country_code' => 'np'])
            ->assertOk()
            ->assertJsonPath('data.country.name', 'Nepal');

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['country_code' => 'FR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');
    });

    it('requires a passport country on new entries', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/passports', passportPayload(['country_code' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country_code');
    });

    it('validates the date of birth and expiry date', function (array $payload, string $field) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // "today" stands for the current date.
        $payload = array_map(fn ($value) => $value === 'today' ? now()->toDateString() : $value, $payload);

        $this->postJson('/api/data-entry/passports', passportPayload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'birth date missing' => [['date_of_birth' => null], 'date_of_birth'],
        'birth date today' => [['date_of_birth' => 'today'], 'date_of_birth'],
        'expiry missing' => [['passport_expiry_date' => null], 'passport_expiry_date'],
        'expiry before received' => [['passport_received_date' => '2026-01-10', 'passport_expiry_date' => '2026-01-09'], 'passport_expiry_date'],
    ]);

    it('compares an edited expiry date with the entry\'s saved received date', function () {
        $clerk = User::factory()->dataEntry()->create();
        $entry = PassportEntry::factory()->createdBy($clerk)->create(['passport_received_date' => '2026-03-01']);
        Sanctum::actingAs($clerk);

        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_expiry_date' => '2026-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passport_expiry_date');
        $this->patchJson("/api/data-entry/passports/{$entry->id}", ['passport_expiry_date' => '2031-02-01'])
            ->assertOk()
            ->assertJsonPath('data.passport_expiry_date', '2031-02-01');
    });

    it('records who deleted an entry', function () {
        $entry = PassportEntry::factory()->create();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/data-entry/passports/{$entry->id}")->assertNoContent();

        expect(PassportEntry::withTrashed()->find($entry->id))
            ->deleted_by->toBe($admin->id)
            ->trashed()->toBeTrue();
    });

    it('filters by company country together with the other filters and search', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $reference = Reference::factory()->create();
        $malaysian = Company::factory()->create(['country_code' => 'MY']);
        $saudi = Company::factory()->create(['country_code' => 'SA']);

        PassportEntry::factory()->create(['passport_number' => 'MY0000001', 'passport_name' => 'Karim', 'company_id' => $malaysian, 'reference_id' => $reference]);
        PassportEntry::factory()->create(['passport_number' => 'SA0000001', 'passport_name' => 'Karim', 'company_id' => $saudi, 'reference_id' => $reference]);
        PassportEntry::factory()->create(['passport_number' => 'SA0000002', 'passport_name' => 'Rahim', 'company_id' => $saudi, 'reference_id' => $reference]);
        PassportEntry::factory()->create(['passport_number' => 'SA0000003', 'passport_name' => 'Karim', 'company_id' => $saudi]);

        $numbers = fn (array $query) => collect($this->getJson('/api/data-entry/passports?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('passport_number')->sort()->values()->all();

        expect($numbers(['company_country_code' => 'sa']))->toBe(['SA0000001', 'SA0000002', 'SA0000003'])
            ->and($numbers(['company_country_code' => 'SA', 'reference_id' => $reference->id]))->toBe(['SA0000001', 'SA0000002'])
            ->and($numbers(['company_country_code' => 'SA', 'reference_id' => $reference->id, 'q' => 'karim']))->toBe(['SA0000001'])
            ->and($numbers(['company_country_code' => 'MY']))->toBe(['MY0000001']);

        $this->getJson('/api/data-entry/passports?company_country_code=SA&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.company.country', ['code' => 'SA', 'name' => 'Saudi Arabia']);
    });
});

describe('admin countries', function () {
    beforeEach(fn () => Sanctum::actingAs(User::factory()->admin()->create()));

    it('lists every country with usage counts and filters by name, code and status', function () {
        Company::factory()->count(2)->create(['country_code' => 'NP']);
        PassportEntry::factory()->count(3)->create(['country_code' => 'NP']);
        Country::whereKey('FR')->update(['is_active' => false]);

        $this->getJson('/api/admin/countries?q=nepal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'NP')
            ->assertJsonPath('data.0.companies_count', 2)
            ->assertJsonPath('data.0.passport_entries_count', 3);

        $this->getJson('/api/admin/countries?q=fr')->assertOk()->assertJsonFragment(['code' => 'FR', 'is_active' => false]);
        $this->getJson('/api/admin/countries?is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'FR');
        $this->getJson('/api/admin/countries')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', Country::count());
        $this->getJson('/api/admin/countries?per_page=300')->assertOk()->assertJsonCount(Country::count(), 'data');
        $this->getJson('/api/admin/countries?per_page=301')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    });

    it('adds a country, active by default', function () {
        $this->postJson('/api/admin/countries', ['code' => ' xa ', 'name' => '  Test   Republic '])
            ->assertCreated()
            ->assertJsonPath('data.code', 'XA')
            ->assertJsonPath('data.name', 'Test Republic')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.companies_count', 0);

        $this->getJson('/api/data-entry/countries')->assertJsonFragment(['code' => 'XA', 'name' => 'Test Republic', 'is_pinned' => false]);
    });

    it('rejects a duplicate or malformed code and a duplicate name', function (array $payload, string $field) {
        $this->postJson('/api/admin/countries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'duplicate code' => [['code' => 'bd', 'name' => 'Another Bangladesh'], 'code'],
        'duplicate name' => [['code' => 'XB', 'name' => 'BANGLADESH'], 'name'],
        'digit in code' => [['code' => 'X1', 'name' => 'Somewhere'], 'code'],
        'three letters' => [['code' => 'XYZ', 'name' => 'Somewhere'], 'code'],
    ]);

    it('renames, pins, reorders and deactivates a country', function () {
        $this->patchJson('/api/admin/countries/sa', ['name' => 'Kingdom of Saudi Arabia', 'is_pinned' => true, 'sort_order' => 3])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kingdom of Saudi Arabia')
            ->assertJsonPath('data.is_pinned', true);

        expect(collect($this->getJson('/api/data-entry/countries')->json('data'))->take(3)->pluck('code')->all())->toBe(['BD', 'MY', 'SA']);

        $this->patchJson('/api/admin/countries/SA', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        expect(Country::find('SA')->updated_by)->toBe(auth()->id());
    });

    it('refuses to deactivate a default country', function () {
        app(Settings::class)->set(Settings::DEFAULT_PASSPORT_COUNTRY, 'BD');

        $this->patchJson('/api/admin/countries/MY', ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active' => 'Malaysia is the default company country and cannot be deactivated. Choose another default first.']);
        $this->patchJson('/api/admin/countries/BD', ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active' => 'Bangladesh is the default passport country and cannot be deactivated. Choose another default first.']);

        expect(Country::whereKey(['MY', 'BD'])->where('is_active', true)->count())->toBe(2);
    });

    it('has no delete endpoint', function () {
        $this->deleteJson('/api/admin/countries/MY')->assertMethodNotAllowed();
    });
});

describe('admin settings', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    });

    it('shows and updates the defaults, recording who changed them', function () {
        $this->getJson('/api/admin/settings')->assertOk()->assertExactJson(['data' => [
            'default_company_country_code' => 'MY',
            'default_passport_country_code' => 'MY',
            'default_company_country' => ['code' => 'MY', 'name' => 'Malaysia'],
            'default_passport_country' => ['code' => 'MY', 'name' => 'Malaysia'],
        ]]);

        $this->putJson('/api/admin/settings', ['default_company_country_code' => 'sa', 'default_passport_country_code' => 'bd'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'default_company_country_code' => 'SA',
                'default_passport_country_code' => 'BD',
                'default_company_country' => ['code' => 'SA', 'name' => 'Saudi Arabia'],
                'default_passport_country' => ['code' => 'BD', 'name' => 'Bangladesh'],
            ]]);

        expect(AppSetting::where('key', Settings::DEFAULT_COMPANY_COUNTRY)->value('updated_by'))->toBe($this->admin->id);

        $this->putJson('/api/admin/settings', ['default_company_country_code' => 'SA', 'default_passport_country_code' => null])
            ->assertOk()
            ->assertJsonPath('data.default_passport_country_code', null)
            // The passport form still gets a country.
            ->assertJsonPath('data.default_passport_country', ['code' => 'MY', 'name' => 'Malaysia']);
    });

    it('rejects inactive, unknown or missing countries', function (array $payload, string $field) {
        Country::whereKey('FR')->update(['is_active' => false]);

        $this->putJson('/api/admin/settings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'inactive company country' => [['default_company_country_code' => 'FR', 'default_passport_country_code' => null], 'default_company_country_code'],
        'inactive passport country' => [['default_company_country_code' => 'MY', 'default_passport_country_code' => 'FR'], 'default_passport_country_code'],
        'unknown country' => [['default_company_country_code' => 'QQ', 'default_passport_country_code' => null], 'default_company_country_code'],
        'company country missing' => [['default_passport_country_code' => null], 'default_company_country_code'],
        'passport key missing' => [['default_company_country_code' => 'MY'], 'default_passport_country_code'],
    ]);

    it('clears the settings cache when a setting changes', function () {
        $settings = app(Settings::class);
        expect($settings->get(Settings::DEFAULT_COMPANY_COUNTRY))->toBe('MY')
            ->and(Cache::has(Settings::CACHE_KEY))->toBeTrue();

        $this->putJson('/api/admin/settings', ['default_company_country_code' => 'AE', 'default_passport_country_code' => null])->assertOk();

        expect($settings->get(Settings::DEFAULT_COMPANY_COUNTRY))->toBe('AE');
        $this->getJson('/api/data-entry/defaults')->assertJsonPath('data.default_company_country_code', 'AE');
    });
});

describe('admin access', function () {
    it('allows only admin and super admin', function (string $role, int $status) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/countries')->assertStatus($status);
        $this->getJson('/api/admin/settings')->assertStatus($status);
    })->with([
        ['dataEntry', 403],
        ['accounts', 403],
        ['admin', 200],
        ['superAdmin', 200],
    ]);

    it('forbids data entry and accounts from changing countries or settings', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson('/api/admin/countries', ['code' => 'XA', 'name' => 'Test Republic'])->assertForbidden();
        $this->patchJson('/api/admin/countries/MY', ['is_active' => false])->assertForbidden();
        $this->putJson('/api/admin/settings', ['default_company_country_code' => 'BD', 'default_passport_country_code' => null])->assertForbidden();
        expect(app(Settings::class)->get(Settings::DEFAULT_COMPANY_COUNTRY))->toBe('MY');
    })->with(['dataEntry', 'accounts']);

    it('rejects guests', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/admin/countries'],
        ['POST', '/api/admin/countries'],
        ['PATCH', '/api/admin/countries/MY'],
        ['GET', '/api/admin/settings'],
        ['PUT', '/api/admin/settings'],
    ]);
});
