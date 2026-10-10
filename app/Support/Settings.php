<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-managed settings from the app_settings table, cached forever and
 * cleared whenever one changes.
 */
class Settings
{
    public const DEFAULT_COMPANY_COUNTRY = 'default_company_country_code';

    public const DEFAULT_PASSPORT_COUNTRY = 'default_passport_country_code';

    public const CACHE_KEY = 'app_settings';

    /**
     * The country used when a default-country setting has never been saved.
     */
    public const FALLBACK_COUNTRY = 'MY';

    /**
     * The value of a setting, or $default when it is not set.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Every setting as key => value.
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()->pluck('value', 'key')->all());
    }

    /**
     * Store a setting, recording who changed it, and clear the cache.
     */
    public function set(string $key, ?string $value, ?User $user = null): void
    {
        $setting = AppSetting::query()->firstOrNew(['key' => $key]);
        $setting->value = $value;

        if ($setting->isDirty() || ! $setting->exists) {
            $setting->updated_by = $user?->id;
            $setting->save();
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The default company and passport countries as {code, name}, from one query.
     *
     * - Passport: always a country. MY when the setting is missing or empty,
     *   or names a country that does not exist or is inactive.
     * - Company: MY when the setting was never saved; one saved empty stays null.
     *
     * @return array{default_company_country: array{code: string, name: string|null}|null, default_passport_country: array{code: string, name: string|null}}
     */
    public function defaultCountries(): array
    {
        $settings = $this->all();
        $company = array_key_exists(self::DEFAULT_COMPANY_COUNTRY, $settings) ? $settings[self::DEFAULT_COMPANY_COUNTRY] : self::FALLBACK_COUNTRY;
        $passport = $settings[self::DEFAULT_PASSPORT_COUNTRY] ?? null;

        $countries = Country::query()
            ->whereIn('code', array_filter([$company, $passport, self::FALLBACK_COUNTRY]))
            ->get(['code', 'name', 'is_active'])
            ->keyBy('code');

        if ($passport === null || ! ($countries[$passport]->is_active ?? false)) {
            $passport = self::FALLBACK_COUNTRY;
        }

        $country = fn (?string $code) => $code === null ? null : ['code' => $code, 'name' => $countries[$code]->name ?? null];

        return [
            'default_company_country' => $country($company),
            'default_passport_country' => $country($passport),
        ];
    }

    /**
     * Country codes that are a default and so cannot be deactivated.
     *
     * @return array<string, string> code => setting key
     */
    public function defaultCountryCodes(): array
    {
        return collect([self::DEFAULT_COMPANY_COUNTRY, self::DEFAULT_PASSPORT_COUNTRY])
            ->mapWithKeys(fn (string $key) => [$this->get($key) => $key])
            ->forget('')
            ->all();
    }
}
