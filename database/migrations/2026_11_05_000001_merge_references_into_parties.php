<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * References and parties are one list: a passport's reference is the
     * client party who gave it. Clients get a kind (agency | person); every
     * old reference (deleted ones included, as inactive) becomes a new client
     * party with the next PT- ID (no matching with existing parties), and
     * passport_entries.reference_id points to parties. Existing clients are
     * agencies (they all have an RL number). For a short Quick Add, a client's
     * mobile, address and NID / passport may be empty (the forms decide).
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('kind', 10)->nullable()->index()->after('type');
            $table->text('notes')->nullable()->after('tin_bin');
            // The old references.id, kept so the merge can be rolled back.
            $table->unsignedBigInteger('legacy_reference_id')->nullable()->unique()->after('notes');
            $table->string('mobile', 30)->nullable()->change();
            $table->string('address', 500)->nullable()->change();
            $table->string('nid_passport', 50)->nullable()->change();
        });

        DB::table('parties')->where('type', 'client')->update(['kind' => 'agency']);

        $sequence = (int) DB::table('parties')->where('type', 'client')->max('sequence');

        foreach (DB::table('references')->orderBy('id')->get() as $reference) {
            $sequence++;

            DB::table('parties')->insert([
                'type' => 'client',
                'kind' => $reference->type,
                'sequence' => $sequence,
                'code' => 'PT-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                'name' => $reference->name,
                'mobile' => $reference->phone,
                'notes' => $reference->notes,
                'legacy_reference_id' => $reference->id,
                'opening_balance' => 0,
                'opening_balance_type' => 'receivable',
                'is_active' => $reference->is_active && $reference->deleted_at === null,
                'created_by' => $reference->created_by,
                'created_at' => $reference->created_at,
                'updated_at' => $reference->updated_at,
            ]);
        }

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropForeign(['reference_id']);
        });

        DB::table('passport_entries')
            ->join('parties', 'parties.legacy_reference_id', '=', 'passport_entries.reference_id')
            ->update(['passport_entries.reference_id' => DB::raw('parties.id')]);

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->foreign('reference_id')->references('id')->on('parties')->restrictOnDelete();
        });

        Schema::drop('references');
    }

    /**
     * Rebuild the references table from the merged parties. Refuses while a
     * passport points to a party that was not an old reference.
     */
    public function down(): void
    {
        $unmapped = DB::table('passport_entries')
            ->join('parties', 'parties.id', '=', 'passport_entries.reference_id')
            ->whereNull('parties.legacy_reference_id')
            ->exists();

        if ($unmapped) {
            throw new RuntimeException('Some passports have a reference added after the merge. Move them to an old reference before rolling this migration back.');
        }

        Schema::create('references', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->index();
            $table->string('name', 150)->index();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['type', 'name']);
        });

        foreach (DB::table('parties')->whereNotNull('legacy_reference_id')->orderBy('legacy_reference_id')->get() as $party) {
            DB::table('references')->insert([
                'id' => $party->legacy_reference_id,
                'type' => $party->kind,
                'name' => $party->name,
                'phone' => $party->mobile,
                'notes' => $party->notes,
                'is_active' => $party->is_active,
                'created_by' => $party->created_by,
                'created_at' => $party->created_at,
                'updated_at' => $party->updated_at,
            ]);
        }

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropForeign(['reference_id']);
        });

        DB::table('passport_entries')
            ->join('parties', 'parties.id', '=', 'passport_entries.reference_id')
            ->update(['passport_entries.reference_id' => DB::raw('parties.legacy_reference_id')]);

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->foreign('reference_id')->references('id')->on('references')->restrictOnDelete();
        });

        // Only the merged references are removed; parties created since stay.
        DB::table('parties')->whereNotNull('legacy_reference_id')->delete();

        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['legacy_reference_id']);
            $table->dropColumn(['kind', 'notes', 'legacy_reference_id']);
        });
    }
};
