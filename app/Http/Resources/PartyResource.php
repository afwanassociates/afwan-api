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
            // Clients only: agency or person (an agency has the RL number).
            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),
            'rl_number' => $this->rl_number,
            'name' => $this->name,
            // Clients only: the agency's owner (whose NID / passport is nid_passport).
            'agency_owner_name' => $this->agency_owner_name,
            'designation' => $this->designation?->value,
            'designation_label' => $this->designation?->label(),
            'mobile' => $this->mobile,
            'address' => $this->address,
            'nid_passport' => $this->nid_passport,
            'tin_bin' => $this->tin_bin,
            'notes' => $this->notes,
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
     * capital, loan_repayment), plus the closing balance: opening +
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
