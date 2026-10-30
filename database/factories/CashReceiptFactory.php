<?php

namespace Database\Factories;

use App\Enums\CashReceiptType;
use App\Enums\PaymentMethod;
use App\Models\CashReceipt;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare receipt row, without its ledger line. Tests that need the ledger
 * create receipts through the API or App\Services\CashReceiptService.
 *
 * @extends Factory<CashReceipt>
 */
class CashReceiptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'year' => 2026,
            'sequence' => $sequence,
            'receipt_no' => sprintf('CR-2026-%04d', $sequence),
            'date' => '2026-10-01',
            'party_id' => Party::factory(),
            'receipt_type' => CashReceiptType::Cash,
            'amount' => 1000,
            'payment_method' => PaymentMethod::Cash,
        ];
    }
}
