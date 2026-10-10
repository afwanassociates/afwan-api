<?php

use App\Services\WorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The slip columns, as named on passport_entries => medical_records.
     *
     * @var array<string, string>
     */
    protected array $columns = [
        'medical_slip_date' => 'slip_date',
        'medical_slip_no' => 'slip_no',
        'medical_center_id' => 'medical_center_id',
    ];

    /**
     * Move the medical slip (the client's "MYGRAM") and the medical centre from
     * medical_records to passport_entries, so they can be saved before a result
     * exists. A passport's medical is "pending" only once its slip date is in.
     */
    public function up(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->date('medical_slip_date')->nullable()->after('passport_expiry_date')->index();
            $table->string('medical_slip_no', 50)->nullable()->after('medical_slip_date');
            $table->foreignId('medical_center_id')->nullable()->after('medical_slip_no')
                ->constrained('medical_centers')->restrictOnDelete();
            // New passports start at the Passport stage (medical not started).
            $table->string('current_stage', 20)->default('passport')->change();
        });

        // Copy each passport's slip from its current medical record, or else its
        // latest record that has one. Only empty passport fields are filled, so
        // running it again changes nothing.
        DB::table('passport_entries')
            ->select(['id', 'current_medical_id', ...array_keys($this->columns)])
            ->orderBy('id')
            ->chunkById(500, function ($passports) {
                foreach ($passports as $passport) {
                    if ($passport->medical_slip_date !== null || $passport->medical_slip_no !== null || $passport->medical_center_id !== null) {
                        continue;
                    }

                    $record = DB::table('medical_records')
                        ->where('passport_entry_id', $passport->id)
                        ->whereNull('deleted_at')
                        ->where(fn ($query) => $query->whereNotNull('slip_date')->orWhereNotNull('slip_no')->orWhereNotNull('medical_center_id'))
                        ->orderByRaw('id = ? desc', [(int) $passport->current_medical_id])
                        ->orderByDesc('medical_date')
                        ->orderByDesc('id')
                        ->first(array_values($this->columns));

                    if ($record !== null) {
                        DB::table('passport_entries')->where('id', $passport->id)->update(
                            collect($this->columns)->map(fn (string $from) => $record->{$from})->all(),
                        );
                    }
                }
            });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_center_id');
            $table->dropColumn(['slip_no', 'slip_date']);
        });

        // Recompute every passport's stage. Passports that already have a
        // medical record keep it (and their stage). A passport that was
        // "pending" with no slip date becomes not_started (stage passport),
        // as a pending medical now needs a slip date.
        app(WorkflowService::class)->recomputeAll();
    }

    /**
     * Put the slip back on medical_records. A passport's slip goes onto its
     * current medical record; a slip saved before any result has no record to
     * go back to and is lost.
     */
    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('medical_center_id')->nullable()->after('passport_entry_id')
                ->constrained('medical_centers')->restrictOnDelete();
            $table->string('slip_no', 50)->nullable()->after('remarks');
            $table->date('slip_date')->nullable()->after('slip_no');
        });

        DB::table('passport_entries')
            ->whereNotNull('current_medical_id')
            ->where(fn ($query) => $query->whereNotNull('medical_slip_date')->orWhereNotNull('medical_slip_no')->orWhereNotNull('medical_center_id'))
            ->select(['id', 'current_medical_id', ...array_keys($this->columns)])
            ->orderBy('id')
            ->chunkById(500, function ($passports) {
                foreach ($passports as $passport) {
                    DB::table('medical_records')
                        ->where('id', $passport->current_medical_id)
                        ->whereNull('slip_date')->whereNull('slip_no')->whereNull('medical_center_id')
                        ->update(collect($this->columns)->mapWithKeys(fn (string $to, string $from) => [$to => $passport->{$from}])->all());
                }
            });

        // Back to the old meaning: no result means medical / pending, dated by
        // the received date.
        DB::table('passport_entries')->where('current_stage', 'passport')->update(['current_stage' => 'medical', 'stage_status' => 'pending']);
        DB::table('passport_entries')->where('current_stage', 'medical')->where('stage_status', 'pending')
            ->update(['stage_status_date' => DB::raw('passport_received_date')]);

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_center_id');
            $table->dropIndex(['medical_slip_date']);
            $table->dropColumn(['medical_slip_date', 'medical_slip_no']);
            $table->string('current_stage', 20)->default('medical')->change();
        });
    }
};
