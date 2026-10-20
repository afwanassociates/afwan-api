<?php

namespace App\Http\Resources;

use App\Enums\PartyTransactionType;
use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A party or owner. With the withTotals() scope loaded it also has the ledger
 * totals and the closing balance (positive: the party owes us).
 *
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'nid_passport' => $this->nid_passport,
            'tin_bin' => $this->tin_bin,
            'opening_balance' => (float) $this->opening_balance,
            'opening_balance_type' => $this->opening_balance_type->value,
            'is_active' => $this->is_active,
            'totals' => $this->when($this->hasTotals(), fn () => $this->totals()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function hasTotals(): bool
    {
        return array_key_exists('receivable_total', $this->resource->getAttributes());
    }

    /**
     * The total of every ledger line type (receivable, payment, refund, loan,
     * capital, loan_repayment, drawing), plus the closing balance: opening +
     * debits − credits.
     *
     * @return array<string, float>
     */
    private function totals(): array
    {
        $totals = [];
        $closing = $this->resource->signedOpeningBalance();

        foreach (PartyTransactionType::cases() as $type) {
            $amount = round((float) $this->resource->getAttribute("{$type->value}_total"), 2);
            $totals[$type->value] = $amount;
            $closing += $type->isDebit() ? $amount : -$amount;
        }

        $totals['closing_balance'] = round($closing, 2);

        return $totals;
    }
}
