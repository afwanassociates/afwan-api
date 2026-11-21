<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts entries need approval (Prepared → Approved): a new entry is
     * pending and only an approved one counts in the cash book, cash in hand
     * and the party ledgers. Entries made before this are marked approved, so
     * the books do not change.
     *
     * @var list<string>
     */
    private array $tables = ['cash_receipts', 'cash_payments', 'cash_transfers', 'party_transactions'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('status', 20)->default('pending')->index();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                // Returned (rejected) for correction: by whom, when and why.
                $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('returned_at')->nullable();
                $table->string('return_reason', 500)->nullable();
            });

            DB::table($name)->update(['status' => 'approved', 'approved_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_by');
                $table->dropConstrainedForeignId('approved_by');
                $table->dropConstrainedForeignId('returned_by');
                $table->dropColumn(['status', 'approved_at', 'returned_at', 'return_reason']);
            });
        }
    }
};
