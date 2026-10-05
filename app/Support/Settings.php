<?php

namespace App\Support;

use App\Models\AppSetting;
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
