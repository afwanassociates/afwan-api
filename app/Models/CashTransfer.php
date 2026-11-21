<?php

namespace App\Models;

use App\Enums\MobileBankingProvider;
use App\Enums\PaymentMethod;
use App\Enums\TransferDirection;
use App\Models\Concerns\HasApproval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash in hand deposited to a bank or mobile banking wallet (channel), or
 * withdrawn from one into cash.
 * Write through App\Services\CashTransferService.
 */
#[Fillable(['date', 'direction', 'channel', 'amount', 'bank_name', 'bank_branch', 'mobile_provider', 'mobile_number', 'cheque_no', 'deposit_slip_no', 'reference_name', 'reference_mobile', 'reference_nid_passport', 'note'])]
class CashTransfer extends Model
{
    use HasApproval;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'year' => 'integer',
            'sequence' => 'integer',
            'direction' => TransferDirection::class,
            'channel' => PaymentMethod::class,
            'mobile_provider' => MobileBankingProvider::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
