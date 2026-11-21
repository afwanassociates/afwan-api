<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passports handed back to their owners. A return is never deleted: undoing
     * it sets undone_at / undone_by, so it stays in the history. The passport's
     * current_return_id points at the return in force (null while in the
     * workflow); its stage columns are left as they were, so an undo puts it
     * back exactly where it stood. Only App\Services\PassportReturnService
     * writes here.
     */
    public function up(): void
    {
        Schema::create('passport_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_entry_id')->constrained('passport_entries')->restrictOnDelete();
            $table->date('return_date')->index();
            $table->string('receiver_name', 100);
            $table->string('receiver_phone', 30);
            // NID or passport number of the receiver.
            $table->string('receiver_id_no', 50)->nullable();
            $table->text('remarks')->nullable();
            // Where the passport stood when it was returned.
            $table->string('stage', 20);
            $table->string('stage_status', 20)->nullable();
            $table->foreignId('returned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->foreignId('current_return_id')->nullable()->after('stage_status_date')
                ->constrained('passport_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_return_id');
        });

        Schema::dropIfExists('passport_returns');
    }
};
