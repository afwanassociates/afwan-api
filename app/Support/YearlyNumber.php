<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Voucher numbers that restart every year: CR-2026-0001, CP-2026-0001, …
 * The model needs year and sequence columns (unique together).
 */
class YearlyNumber
{
    /**
     * Give a new voucher the next number of its date's year and return it.
     * Call inside a transaction: the lock makes two vouchers saved at once get
     * different numbers.
     */
    public static function assign(Model $voucher, string $date, string $prefix): string
    {
        $year = LocalDate::parse($date)->year;
        $last = (int) $voucher->newQuery()->where('year', $year)->lockForUpdate()->max('sequence');

        $voucher->setAttribute('year', $year);
        $voucher->setAttribute('sequence', $last + 1);

        return sprintf('%s-%d-%04d', $prefix, $year, $last + 1);
    }
}
