<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PartyCompanyRate;
use App\Models\PassportEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Passport prices for the payment report. A passport's price is locked when it
 * is entered, or when its reference or company changes: the reference's rate
 * for the company, else the company's standard rate, else none. The accounts
 * head may set a special price on one passport instead.
 *
 * Saving a rate fills the passports of that scope that have no price yet;
 * with $updateExisting, it also changes the ones priced from a rate before
 * (never a special price).
 */
class PassportPricing
{
    public const COMPANY = 'company';

    public const REFERENCE = 'reference';

    public const SPECIAL = 'special';

    /**
     * The current rate for a reference's passports of a company.
     *
     * @return array{0: string|null, 1: string|null} [price, source]
     */
    public static function rateFor(?int $referenceId, ?int $companyId): array
    {
        if ($companyId === null) {
            return [null, null];
        }
        if ($referenceId !== null) {
            $own = PartyCompanyRate::query()->where('party_id', $referenceId)->where('company_id', $companyId)->value('rate');
            if ($own !== null) {
                return [(string) $own, self::REFERENCE];
            }
        }
        $standard = Company::withTrashed()->whereKey($companyId)->value('rate');

        return $standard !== null ? [(string) $standard, self::COMPANY] : [null, null];
    }

    /** Lock the price of a new passport, or of one whose reference or company changed. */
    public static function lock(PassportEntry $passport): void
    {
        if ($passport->exists && ! $passport->isDirty(['reference_id', 'company_id'])) {
            return;
        }
        [$passport->price, $passport->price_source] = self::rateFor($passport->reference_id, $passport->company_id);
    }

    /**
     * Set (or, with null, remove) a company's standard rate.
     *
     * @return array{filled: int, updated: int, kept: int}
     */
    public static function setCompanyRate(Company $company, ?float $rate, bool $updateExisting): array
    {
        $company->forceFill(['rate' => $rate])->save();

        // Passports of references with their own rate for this company keep it.
        return self::apply(PassportEntry::query()
            ->where('company_id', $company->id)
            ->whereNotIn('reference_id', PartyCompanyRate::query()->where('company_id', $company->id)->select('party_id')), $updateExisting);
    }

    /**
     * Set (or, with null, remove) a reference's own rate for a company.
     *
     * @return array{filled: int, updated: int, kept: int}
     */
    public static function setReferenceRate(int $referenceId, int $companyId, ?float $rate, bool $updateExisting): array
    {
        if ($rate === null) {
            PartyCompanyRate::query()->where('party_id', $referenceId)->where('company_id', $companyId)->delete();
        } else {
            PartyCompanyRate::query()->updateOrCreate(['party_id' => $referenceId, 'company_id' => $companyId], ['rate' => $rate]);
        }

        return self::apply(PassportEntry::query()
            ->where('company_id', $companyId)
            ->where('reference_id', $referenceId), $updateExisting);
    }

    /** Set a special price on one passport; null goes back to the current rate. */
    public static function setSpecial(PassportEntry $passport, ?float $price): void
    {
        [$passport->price, $passport->price_source] = $price === null
            ? self::rateFor($passport->reference_id, $passport->company_id)
            : [(string) $price, self::SPECIAL];
        $passport->saveQuietly();
        PassportLedger::sync($passport);
    }

    /**
     * Re-price the passports of a scope from the current rates (special prices
     * stay): those without a price always, the others only with $updateExisting.
     *
     * @param  Builder<PassportEntry>  $scope
     * @return array{filled: int, updated: int, kept: int}
     */
    private static function apply(Builder $scope, bool $updateExisting): array
    {
        $result = ['filled' => 0, 'updated' => 0, 'kept' => 0];

        $scope->where(fn (Builder $q) => $q->whereNull('price_source')->orWhere('price_source', '!=', self::SPECIAL))
            ->get(['id', 'entry_no', 'passport_name', 'reference_id', 'company_id', 'price', 'price_source', 'current_return_id', 'deleted_at'])
            ->each(function (PassportEntry $passport) use ($updateExisting, &$result) {
                [$price, $source] = self::rateFor($passport->reference_id, $passport->company_id);
                $unpriced = $passport->price === null;
                if (! $unpriced && ! $updateExisting) {
                    if ((string) $passport->price !== (string) $price) {
                        $result['kept']++;
                    }

                    return;
                }
                if ((string) $passport->price === (string) $price && $passport->price_source === $source) {
                    return;
                }
                $passport->forceFill(['price' => $price, 'price_source' => $source])->saveQuietly();
                PassportLedger::sync($passport);
                $result[$unpriced ? 'filled' : 'updated']++;
            });

        return $result;
    }

    /** Per receipt line: how many passports, their prices' sum and how many have none. */
    public static function lineStats(): QueryBuilder
    {
        return DB::table('cash_receipt_passports as x')
            ->join('passport_entries as xp', 'xp.id', '=', 'x.passport_entry_id')
            ->selectRaw('x.cash_receipt_line_id, count(*) as n, sum(xp.price) as price_sum, sum(case when xp.price is null then 1 else 0 end) as unpriced')
            ->groupBy('x.cash_receipt_line_id');
    }

    /**
     * Per passport: its shares of every receipt line, approved or not. A line's
     * amount is split by its passports' prices (evenly while one has none).
     * $exceptReceiptId leaves out the receipt being edited.
     */
    public static function shares(?int $exceptReceiptId = null): QueryBuilder
    {
        return DB::table('cash_receipt_passports as crp')
            ->join('cash_receipt_lines as l', 'l.id', '=', 'crp.cash_receipt_line_id')
            ->join('passport_entries as pe', 'pe.id', '=', 'crp.passport_entry_id')
            ->joinSub(self::lineStats(), 'lc', 'lc.cash_receipt_line_id', '=', 'l.id')
            ->when($exceptReceiptId, fn (QueryBuilder $q, int $id) => $q->where('crp.cash_receipt_id', '!=', $id))
            ->selectRaw('crp.passport_entry_id, sum(case when lc.unpriced = 0 and lc.price_sum > 0'
                .' then l.amount * pe.price / lc.price_sum else l.amount / lc.n end) as paid')
            ->groupBy('crp.passport_entry_id');
    }
}
