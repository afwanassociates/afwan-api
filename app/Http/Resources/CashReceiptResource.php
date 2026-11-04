<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ApprovalFields;
use App\Models\CashReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cash receipt with the payer's details from their party record.
 *
 * @mixin CashReceipt
 */
class CashReceiptResource extends JsonResource
{
    use ApprovalFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'date' => $this->date->format('Y-m-d'),
            'party' => $this->whenLoaded('party', fn () => [
                'id' => $this->party->id,
                'code' => $this->party->code,
                'type' => $this->party->type->value,
                'name' => $this->party->name,
                'mobile' => $this->party->mobile,
                'address' => $this->party->address,
            ]),
            'receipt_type' => $this->receipt_type->value,
            'receipt_type_label' => $this->receipt_type->label(),
            // A loan return: the owner loan (a cash payment) it settles.
            'owner_loan' => $this->whenLoaded('ownerLoan', fn () => $this->ownerLoan ? [
                'id' => $this->ownerLoan->id,
                'payment_no' => $this->ownerLoan->payment_no,
            ] : null),
            'business_type' => $this->business_type?->value,
            'business_type_label' => $this->business_type?->label(),
            'country' => $this->country_code === null ? null : [
                'code' => $this->country_code,
                'name' => $this->country?->name,
            ],
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'mobile_provider' => $this->mobile_provider?->value,
            'mobile_provider_label' => $this->mobile_provider?->label(),
            'mobile_number' => $this->mobile_number,
            'note' => $this->note,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            ...$this->approvalFields($request),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
