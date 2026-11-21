<?php

namespace App\Enums;

use App\Models\MedicalRecord;
use App\Support\DateRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A passport's medical status, worked out from its current medical record,
 * its medical slip date and today's date in the business timezone (see
 * App\Support\LocalDate): not_started until the slip date is entered, then
 * pending until a result is recorded.
 *
 * expiring_soon is a subset of fit: a passport is shown as expiring_soon, but
 * the fit filter and the fit count include it.
 */
enum MedicalStatus: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case Fit = 'fit';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Unfit = 'unfit';

    /**
     * Human readable name of the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Medical Not Started',
            self::Pending => 'Medical Pending',
            self::Fit => 'Fit',
            self::ExpiringSoon => 'Fit (Expiring Soon)',
            self::Expired => 'Medical Expired',
            self::Unfit => 'Unfit',
        };
    }

    /**
     * Whether the passport holds a fit result that is still valid.
     */
    public function isPassed(): bool
    {
        return $this === self::Fit || $this === self::ExpiringSoon;
    }

    /**
     * The status for a passport whose current medical record is $record and
     * whose medical slip date is (or is not) entered.
     */
    public static function fromRecord(?MedicalRecord $record, CarbonImmutable $today, bool $slipEntered = true, ?string $country = null): self
    {
        if ($record === null) {
            return $slipEntered ? self::Pending : self::NotStarted;
        }

        if ($record->result === MedicalResult::Unfit) {
            return self::Unfit;
        }

        $validUntil = $record->valid_until->toDateString();

        return match (true) {
            $validUntil < $today->toDateString() => self::Expired,
            $validUntil <= self::expiringSoonLimit($today, $country)->toDateString() => self::ExpiringSoon,
            default => self::Fit,
        };
    }

    /**
     * Restrict a query that has joined the current medical record as $alias
     * (see PassportEntry::scopeWithCurrentMedical) to passports in this status.
     */
    public function applyTo(Builder $query, string $alias, CarbonImmutable $today): void
    {
        [$soonSql, $soonBindings] = $this === self::ExpiringSoon ? self::expiringSoonLimitSql($today) : ['?', []];

        match ($this) {
            self::NotStarted => $query->whereNull("{$alias}.id")->whereNull('passport_entries.medical_slip_date'),
            self::Pending => $query->whereNull("{$alias}.id")->whereNotNull('passport_entries.medical_slip_date'),
            self::Unfit => $query->where("{$alias}.result", MedicalResult::Unfit->value),
            self::Fit => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->where("{$alias}.valid_until", '>=', $today->toDateString()),
            self::ExpiringSoon => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->where("{$alias}.valid_until", '>=', $today->toDateString())
                // Each passport's limit by its company country (Settings → Date rules).
                ->whereRaw("{$alias}.valid_until <= {$soonSql}", $soonBindings),
            self::Expired => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->where("{$alias}.valid_until", '<', $today->toDateString()),
        };
    }

    /**
     * The last valid_until date that still counts as expiring soon (the "Medical expiring
     * soon" date rule).
     */
    public static function expiringSoonLimit(CarbonImmutable $today, ?string $country = null): CarbonImmutable
    {
        return $today->addDays(app(DateRules::class)->get(DateRules::MEDICAL_EXPIRING_SOON_DAYS, $country));
    }

    /**
     * The same limit as SQL for a query over passport_entries: each passport by its
     * company's country (a plain placeholder when no country has its own value).
     *
     * @return array{0: string, 1: list<string|int>}
     */
    public static function expiringSoonLimitSql(CarbonImmutable $today): array
    {
        return app(DateRules::class)->perCountrySql(
            DateRules::MEDICAL_EXPIRING_SOON_DAYS,
            fn (int $days) => $today->addDays($days)->toDateString(),
        );
    }
}
