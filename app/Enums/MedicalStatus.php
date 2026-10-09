<?php

namespace App\Enums;

use App\Models\MedicalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * A passport's medical status, worked out from its current medical record and
 * today's date in the business timezone (see App\Support\LocalDate).
 *
 * expiring_soon is a subset of fit: a passport is shown as expiring_soon, but
 * the fit filter and the fit count include it.
 */
enum MedicalStatus: string
{
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
     * The status for a passport whose current medical record is $record.
     */
    public static function fromRecord(?MedicalRecord $record, CarbonImmutable $today): self
    {
        if ($record === null) {
            return self::Pending;
        }

        if ($record->result === MedicalResult::Unfit) {
            return self::Unfit;
        }

        $validUntil = $record->valid_until->toDateString();

        return match (true) {
            $validUntil < $today->toDateString() => self::Expired,
            $validUntil <= self::expiringSoonLimit($today)->toDateString() => self::ExpiringSoon,
            default => self::Fit,
        };
    }

    /**
     * Restrict a query that has joined the current medical record as $alias
     * (see PassportEntry::scopeWithCurrentMedical) to passports in this status.
     */
    public function applyTo(Builder $query, string $alias, CarbonImmutable $today): void
    {
        match ($this) {
            self::Pending => $query->whereNull("{$alias}.id"),
            self::Unfit => $query->where("{$alias}.result", MedicalResult::Unfit->value),
            self::Fit => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->where("{$alias}.valid_until", '>=', $today->toDateString()),
            self::ExpiringSoon => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->whereBetween("{$alias}.valid_until", [$today->toDateString(), self::expiringSoonLimit($today)->toDateString()]),
            self::Expired => $query->where("{$alias}.result", MedicalResult::Fit->value)
                ->where("{$alias}.valid_until", '<', $today->toDateString()),
        };
    }

    /**
     * The last valid_until date that still counts as expiring soon.
     */
    public static function expiringSoonLimit(CarbonImmutable $today): CarbonImmutable
    {
        return $today->addDays((int) config('medical.expiring_soon_days'));
    }
}
