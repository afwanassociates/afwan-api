<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ApprovalFields;
use App\Models\CashTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashTransfer
 */
class CashTransferResource extends JsonResource
{
    use ApprovalFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transfer_no' => $this->transfer_no,
            'date' => $this->date->format('Y-m-d'),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->labelFor($this->channel),
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'amount' => (float) $this->amount,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'mobile_provider' => $this->mobile_provider?->value,
            'mobile_provider_label' => $this->mobile_provider?->label(),
            'mobile_number' => $this->mobile_number,
            'cheque_no' => $this->cheque_no,
            'deposit_slip_no' => $this->deposit_slip_no,
            'reference_name' => $this->reference_name,
            'reference_mobile' => $this->reference_mobile,
            'reference_nid_passport' => $this->reference_nid_passport,
            'note' => $this->note,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            ...$this->approvalFields($request),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
