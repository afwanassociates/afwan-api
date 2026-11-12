<?php

use App\Support\LocalDate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A passport's price on its reference's ledger: one approved receivable line
     * per priced passport (App\Support\PassportLedger keeps it in step with the
     * price). Deleting the passport row deletes its line. Passports priced
     * already get theirs now.
     */
    public function up(): void
    {
        Schema::table('party_transactions', function (Blueprint $table) {
            $table->foreignId('passport_entry_id')->nullable()->unique()->after('owner_loan_bill_id')
                ->constrained('passport_entries')->cascadeOnDelete();
        });

        $today = LocalDate::today()->toDateString();
        DB::table('passport_entries')
            ->leftJoin('companies', 'companies.id', '=', 'passport_entries.company_id')
            ->whereNull('passport_entries.deleted_at')
            ->whereNull('passport_entries.current_return_id')
            ->whereNotNull('passport_entries.reference_id')
            ->whereNotNull('passport_entries.price')
            ->orderBy('passport_entries.id')
            ->select('passport_entries.id', 'passport_entries.entry_no', 'passport_entries.passport_name', 'passport_entries.reference_id', 'passport_entries.price', 'companies.name as company_name')
            ->get()
            ->each(fn (object $p) => DB::table('party_transactions')->insert([
                'party_id' => $p->reference_id,
                'passport_entry_id' => $p->id,
                'date' => $today,
                'type' => 'receivable',
                'amount' => $p->price,
                'note' => mb_substr("Passport #{$p->entry_no} {$p->passport_name}".($p->company_name ? " · {$p->company_name}" : ''), 0, 500),
                'status' => 'approved',
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    public function down(): void
    {
        DB::table('party_transactions')->whereNotNull('passport_entry_id')->delete();
        Schema::table('party_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('passport_entry_id');
        });
    }
};
