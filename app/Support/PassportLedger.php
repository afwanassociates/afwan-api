<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Enums\PartyTransactionType;
use App\Models\Company;
use App\Models\PartyTransaction;
use App\Models\PassportEntry;

/**
 * A passport's price on its reference's ledger: one receivable line (the party
 * owes us) per passport with a price, so the ledger balance shows what is due.
 * The line follows the passport: it changes with the price, moves with the
 * reference and goes when the passport is returned, deleted or loses its price.
 *
 * The line is a system entry, approved at once: the price comes from the rates
 * only the accounts head sets (App\Support\PassportPricing). Nobody edits or
 * deletes it by hand (PartyTransaction::isManual()).
 */
class PassportLedger
{
    public static function sync(PassportEntry $passport): void
    {
        $line = PartyTransaction::query()->where('passport_entry_id', $passport->id)->first();
        $owes = ! $passport->trashed()
            && $passport->current_return_id === null
            && $passport->reference_id !== null
            && $passport->price !== null;

        if (! $owes) {
            $line?->delete();

            return;
        }

        $values = [
            'party_id' => $passport->reference_id,
            'amount' => (string) $passport->price,
            'note' => self::note($passport),
        ];

        if ($line !== null) {
            $line->forceFill($values);
            if ($line->isDirty()) {
                $line->save();
            }

            return;
        }

        (new PartyTransaction)->forceFill([
            ...$values,
            'passport_entry_id' => $passport->id,
            'date' => LocalDate::today()->toDateString(),
            'type' => PartyTransactionType::Receivable,
            'status' => ApprovalStatus::Approved,
            'approved_at' => now(),
        ])->save();
    }

    private static function note(PassportEntry $passport): string
    {
        $company = $passport->company_id
            ? Company::withTrashed()->whereKey($passport->company_id)->value('name')
            : null;

        return mb_substr("Passport #{$passport->entry_no} {$passport->passport_name}".($company ? " · {$company}" : ''), 0, 500);
    }
}
