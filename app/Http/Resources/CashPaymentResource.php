<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ApprovalFields;
use App\Models\CashPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cash payment; a party/owner payee comes with their details from the party record.
 *
 * @mixin CashPayment
 */
class CashPaymentResource extends JsonResource
{
    use ApprovalFields;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_no' => $this->payment_no,
            'date' => $this->date->format('Y-m-d'),
            'payee_type' => $this->payee_type->value,
            'payee_type_label' => $this->payee_type->label(),
            'party' => $this->whenLoaded('party', fn () => $this->party ? [
                'id' => $this->party->id,
                'code' => $this->party->code,
                'type' => $this->party->type->value,
                'name' => $this->party->name,
                'mobile' => $this->party->mobile,
                'address' => $this->party->address,
            ] : null),
            'payee_name' => $this->payeeName(),
            'payment_type' => $this->payment_type->value,
            'payment_type_label' => $this->payment_type->label(),
            'loan_receipt' => $this->whenLoaded('loanReceipt', fn () => $this->loanReceipt ? [
                'id' => $this->loanReceipt->id,
                'receipt_no' => $this->loanReceipt->receipt_no,
            ] : null),
            'expense_category' => $this->expense_category?->value,
            'expense_category_label' => $this->expense_category?->label(),
            'purpose' => $this->purpose,
            'bill_no' => $this->bill_no,
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
