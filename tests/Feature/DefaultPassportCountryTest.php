<?php

use App\Models\AppSetting;
use App\Models\Country;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The passport default as the passport form receives it.
 *
 * @return array<string, mixed>|null
 */
function passportDefault(): ?array
{
    return test()->getJson('/api/data-entry/defaults')->assertOk()->json('data.default_passport_country');
}

/**
 * Point the stored passport default at $value, bypassing validation.
 */
function storePassportDefault(?string $value): void
{
    DB::table('app_settings')->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->update(['value' => $value]);
    Cache::forget(Settings::CACHE_KEY);
}

beforeEach(function () {
    Sanctum::actingAs(User::factory()->dataEntry()->create());
});

describe('stored data', function () {
    it('has Malaysia active and the default passport country row set to MY', function () {
        expect(Country::find('MY'))
            ->name->toBe('Malaysia')
            ->is_active->toBeTrue()
            ->and(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value'))->toBe('MY');
    });

    it('is safe to run the migration again, never overriding a chosen default', function () {
        $migration = require base_path('database/migrations/2026_10_15_000001_ensure_malaysia_default_passport_country.php');

        // A chosen default and a deactivated Malaysia.
        storePassportDefault('BD');
        Country::query()->where('code', 'MY')->update(['is_active' => false]);

        $migration->up();
        $migration->up();

        expect(Country::find('MY')->is_active)->toBeTrue()
            ->and(Country::query()->where('code', 'MY')->count())->toBe(1)
            ->and(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->count())->toBe(1)
            ->and(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value'))->toBe('BD');

        // An empty value, or a missing row, is filled with MY.
        storePassportDefault(null);
        $migration->up();
        expect(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value'))->toBe('MY');

        AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->delete();
        $migration->up();
        expect(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value'))->toBe('MY');
    });

    it('keeps the setting row after seeding', function () {
        $this->seed();

        expect(AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value'))->toBe('MY');
    });
});

describe('default passport country', function () {
    it('returns the chosen country as {code, name}', function () {
        storePassportDefault('BD');

        expect(passportDefault())->toBe(['code' => 'BD', 'name' => 'Bangladesh']);
    });

    it('falls back to Malaysia', function (Closure $setUp) {
        $setUp();

        expect(passportDefault())->toBe(['code' => 'MY', 'name' => 'Malaysia']);
    })->with([
        'row missing' => fn () => function () {
            AppSetting::query()->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->delete();
            Cache::forget(Settings::CACHE_KEY);
        },
        'saved empty' => fn () => fn () => storePassportDefault(null),
        'inactive country' => fn () => function () {
            Country::query()->where('code', 'NP')->update(['is_active' => false]);
            storePassportDefault('NP');
        },
        'country no longer in the list' => fn () => fn () => storePassportDefault('QQ'),
    ]);

    it('has the same shape on the admin settings endpoint', function () {
        Sanctum::actingAs(User::factory()->admin()->create());
        storePassportDefault('QQ');

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.default_passport_country', ['code' => 'MY', 'name' => 'Malaysia'])
            ->assertJsonStructure(['data' => ['default_passport_country' => ['code', 'name'], 'default_company_country', 'default_passport_country_code', 'default_company_country_code']]);
    });

    it('always returns {code, name} with both keys filled', function () {
        $default = passportDefault();

        expect($default)->toHaveKeys(['code', 'name'])
            ->and($default['code'])->toBeString()->toHaveLength(2)
            ->and($default['name'])->toBeString()->not->toBeEmpty();
    });
});
