<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * The date rules the super admin sets per company country (Settings → Date rules): how
 * long a result is valid, how early an expiry warns, and the passport limits. Each is a
 * whole number of days, months or years, stored in app_settings as
 * date_rule:{COUNTRY}:{rule}. A passport uses the rules of its company's country (where
 * the worker goes; Malaysia today).
 *
 * There are no rules "for all countries": a country gets its rules when the company starts
 * working there (every rule is required then). Until it has them, its passports use the
 * built-in defaults below, as a safety net only.
 *
 * A change applies from then on: dates already saved on records (a medical's expiry, a
 * visa's expiry) keep the value they were saved with.
 */
class DateRules
{
    public const MEDICAL_VALIDITY_DAYS = 'medical_validity_days';

    public const MEDICAL_EXPIRING_SOON_DAYS = 'medical_expiring_soon_days';

    public const CALLING_VALIDITY_MONTHS = 'calling_validity_months';

    public const VISA_VALIDITY_MONTHS = 'visa_validity_months';

    public const PASSPORT_MIN_VALIDITY_MONTHS = 'passport_min_validity_months';

    public const PASSPORT_EXPIRY_WARNING_MONTHS = 'passport_expiry_warning_months';

    public const MINIMUM_AGE_YEARS = 'minimum_age_years';

    /** A country's own values are stored as date_rule:{CODE}:{rule}. */
    public const COUNTRY_PREFIX = 'date_rule:';

    /** The steps whose expiry dates warn (their warning_days in config/workflow.php). */
    public const WARNING_STEPS = ['calling', 'visa', 'bmet', 'flight'];

    /**
     * Every rule: its group, label, help text, unit, limits and default. The page lists
     * them in this order.
     *
     * @return array<string, array{group: string, label: string, help: string, unit: string, min: int, max: int, default: int}>
     */
    public function definitions(): array
    {
        $steps = collect(config('workflow.steps', []))->keyBy('key');
        $warning = fn (string $step) => (int) ($steps[$step]['warning_days'] ?? 30);
        $label = fn (string $step) => (string) ($steps[$step]['label'] ?? ucfirst($step));

        return [
            self::MEDICAL_VALIDITY_DAYS => [
                'group' => 'Medical',
                'label' => 'Fit result validity',
                'help' => 'Prefills the expiry date of a fit medical: issue date plus this many days.',
                'unit' => 'days', 'min' => 1, 'max' => 730,
                'default' => (int) config('medical.validity_days', 90),
            ],
            self::MEDICAL_EXPIRING_SOON_DAYS => [
                'group' => 'Medical',
                'label' => 'Medical "expiring soon"',
                'help' => 'A fit medical moves to Expiring soon when this many days or fewer are left.',
                'unit' => 'days', 'min' => 0, 'max' => 365,
                'default' => (int) config('medical.expiring_soon_days', 14),
            ],
            self::CALLING_VALIDITY_MONTHS => [
                'group' => 'Expiry prefill',
                'label' => 'Calling / work permit validity',
                'help' => 'Prefills the calling expiry date: issued date plus this many months.',
                'unit' => 'months', 'min' => 1, 'max' => 60,
                'default' => 3,
            ],
            self::VISA_VALIDITY_MONTHS => [
                'group' => 'Expiry prefill',
                'label' => 'Visa validity',
                'help' => 'Prefills the visa expired date: issue date plus this many months.',
                'unit' => 'months', 'min' => 1, 'max' => 60,
                'default' => 3,
            ],
            ...collect(self::WARNING_STEPS)->mapWithKeys(fn (string $step) => [
                self::warningKey($step) => [
                    'group' => 'Expiry warnings',
                    'label' => $label($step),
                    'help' => "An expiry date shown on the {$label($step)} page turns amber when this many days or fewer are left.",
                    'unit' => 'days', 'min' => 0, 'max' => 365,
                    'default' => $warning($step),
                ],
            ])->all(),
            self::PASSPORT_MIN_VALIDITY_MONTHS => [
                'group' => 'Passport',
                'label' => 'Minimum passport validity',
                'help' => 'A new passport must be valid for at least this many months after it is received.',
                'unit' => 'months', 'min' => 0, 'max' => 120,
                'default' => 18,
            ],
            self::PASSPORT_EXPIRY_WARNING_MONTHS => [
                'group' => 'Passport',
                'label' => 'Passport expiry warning',
                'help' => 'Warn when the passport expires less than this many months after the flight (or after today at Visa and BMET).',
                'unit' => 'months', 'min' => 0, 'max' => 60,
                'default' => (int) config('workflow.passport_expiry_warning_months', 6),
            ],
            self::MINIMUM_AGE_YEARS => [
                'group' => 'Passport',
                'label' => 'Minimum age',
                'help' => 'The person must be at least this many years old on the day the passport is entered.',
                'unit' => 'years', 'min' => 0, 'max' => 100,
                'default' => 18,
            ],
        ];
    }

    public function __construct(private readonly Settings $settings) {}

    /** The settings key of a step's expiry warning, e.g. visa_warning_days. */
    public static function warningKey(string $step): string
    {
        return "{$step}_warning_days";
    }

    /** The settings key of a country's own value for a rule, e.g. date_rule:SG:visa_warning_days. */
    public static function countryKey(string $country, string $key): string
    {
        return self::COUNTRY_PREFIX.strtoupper($country).':'.$key;
    }

    /**
     * A rule's value for a company country: the country's value, else the built-in default
     * (a country without rules yet, or no country).
     */
    public function get(string $key, ?string $country = null): int
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown date rule {$key}.");
        }

        // Always through the (cached) settings, so a request's queries never depend on which
        // rule is asked for first.
        $settings = $this->settings->all();

        if ($country !== null && $country !== '') {
            $own = $settings[self::countryKey($country, $key)] ?? null;
            if (is_numeric($own)) {
                return (int) $own;
            }
        }

        return $definition['default'];
    }

    /**
     * The built-in default of a rule (for countries without rules).
     */
    public function defaultValue(string $key): int
    {
        return $this->get($key);
    }

    /**
     * A country's value for a rule, or null when it has none.
     */
    public function ownValue(string $key, string $country): ?int
    {
        $own = $this->settings->get(self::countryKey($country, $key));

        return is_numeric($own) ? (int) $own : null;
    }

    /**
     * The countries that have their own value for a rule: code => value.
     *
     * @return array<string, int>
     */
    public function countryValues(string $key): array
    {
        $values = [];
        foreach ($this->settings->all() as $settingKey => $value) {
            if (! is_numeric($value) || ! str_starts_with($settingKey, self::COUNTRY_PREFIX)) {
                continue;
            }
            [$country, $rule] = explode(':', substr($settingKey, strlen(self::COUNTRY_PREFIX)), 2) + [null, null];
            if ($rule === $key && $country !== null) {
                $values[$country] = (int) $value;
            }
        }
        ksort($values);

        return $values;
    }

    /**
     * Whether any country has its own value for the rule (when none does, nobody needs to
     * look up a passport's country).
     */
    public function variesByCountry(string $key): bool
    {
        return $this->countryValues($key) !== [];
    }

    /**
     * The countries with their own value for at least one rule, sorted by code.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        $codes = [];
        foreach ($this->settings->all() as $settingKey => $value) {
            if (is_numeric($value) && str_starts_with($settingKey, self::COUNTRY_PREFIX)) {
                $codes[] = explode(':', substr($settingKey, strlen(self::COUNTRY_PREFIX)), 2)[0];
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * A step's expiry warning in days (for the passport's company country), or null for a
     * step without one.
     */
    public function warningDays(string $step, ?string $country = null): ?int
    {
        return in_array($step, self::WARNING_STEPS, true) ? $this->get(self::warningKey($step), $country) : null;
    }

    /**
     * Every rule's value for a country (the defaults without one).
     *
     * @return array<string, int>
     */
    public function values(?string $country = null): array
    {
        return collect(array_keys($this->definitions()))->mapWithKeys(fn (string $key) => [$key => $this->get($key, $country)])->all();
    }

    /**
     * Every rule's value for each country that has its own values.
     *
     * @return array<string, array<string, int>>
     */
    public function valuesByCountry(): array
    {
        return collect($this->countries())->mapWithKeys(fn (string $code) => [$code => $this->values($code)])->all();
    }

    /** company id => country code, loaded once (with one query) when first needed. */
    private ?array $companyCountries = null;

    /** passport id => company id, for step records whose passport is already loaded. */
    private array $passportCompanies = [];

    /**
     * The company country of a passport, looked up only when the rule differs by country.
     * Uses the passport's company_id, so lists never load each company.
     */
    public function countryOf(?PassportEntry $passport, string $key): ?string
    {
        if ($passport === null || ! $this->variesByCountry($key)) {
            return null;
        }
        $this->passportCompanies[$passport->id] = $passport->company_id;

        return $this->countryOfCompany($passport->company_id);
    }

    /**
     * The company country of the passport with this id (for a step record), looked up only
     * when the rule differs by country.
     */
    public function countryOfPassport(int $passportId, string $key): ?string
    {
        if (! $this->variesByCountry($key)) {
            return null;
        }
        if (! array_key_exists($passportId, $this->passportCompanies)) {
            $this->passportCompanies[$passportId] = PassportEntry::withTrashed()->whereKey($passportId)->value('company_id');
        }

        return $this->countryOfCompany($this->passportCompanies[$passportId]);
    }

    /** Remember a loaded passport's company, so its step records need no query. */
    public function rememberPassport(PassportEntry $passport): void
    {
        $this->passportCompanies[$passport->id] = $passport->company_id;
    }

    private function countryOfCompany(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }
        if ($this->companyCountries === null || ! array_key_exists($companyId, $this->companyCountries)) {
            // First use, or a company added since: one query for every company.
            $this->companyCountries = Company::query()->pluck('country_code', 'id')->all();
            // Remembered as unknown, so it never causes another reload.
            $this->companyCountries[$companyId] ??= null;
        }

        return $this->companyCountries[$companyId] ?? null;
    }

    /**
     * A SQL expression for each passport's value of a rule, by the country of its company
     * ($passports is the passport_entries table or its alias), mapped through $map (e.g.
     * days to a limit date). A plain placeholder when no country has its own value.
     *
     * @param  callable(int): (string|int)  $map
     * @return array{0: string, 1: list<string|int>}
     */
    public function perCountrySql(string $key, callable $map, string $passports = 'passport_entries'): array
    {
        $global = $map($this->defaultValue($key));
        $countries = $this->countryValues($key);
        if ($countries === []) {
            return ['?', [$global]];
        }

        $sql = "case (select companies.country_code from companies where companies.id = {$passports}.company_id)";
        $bindings = [];
        foreach ($countries as $code => $value) {
            $sql .= ' when ? then ?';
            array_push($bindings, $code, $map($value));
        }

        return [$sql.' else ? end', [...$bindings, $global]];
    }

    /**
     * Save a country's rules (null removes a rule: the default applies again).
     *
     * @param  array<string, int|null>  $values
     */
    public function save(array $values, ?User $user, string $country): void
    {
        foreach ($values as $key => $value) {
            $this->settings->set(
                self::countryKey($country, $key),
                $value === null ? null : (string) (int) $value,
                $user,
            );
        }
    }

    /**
     * How many rules a country has a value for.
     */
    public function countryValuesCount(string $country): int
    {
        return count(array_filter(array_keys($this->definitions()), fn (string $key) => $this->ownValue($key, $country) !== null));
    }

    /**
     * Whether a country has a value for every rule.
     */
    public function isComplete(string $country): bool
    {
        foreach (array_keys($this->definitions()) as $key) {
            if ($this->ownValue($key, $country) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Give a country the built-in default for every rule it does not have yet (e.g. the
     * first rules for Malaysia). Rules it already has keep their value.
     *
     * @return array<string, int> the rules set: key => value
     */
    public function copyToCountry(string $country, ?User $user = null): array
    {
        $copied = [];
        foreach (array_keys($this->definitions()) as $key) {
            if ($this->ownValue($key, $country) === null) {
                $copied[$key] = $this->defaultValue($key);
            }
        }
        $this->save($copied, $user, strtoupper($country));

        return $copied;
    }

    /**
     * Remove a country's rules: its passports use the built-in defaults until it gets
     * rules again.
     */
    public function clearCountry(string $country, User $user): void
    {
        $this->save(array_fill_keys(array_keys($this->definitions()), null), $user, $country);
    }
}
