<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A passport's medical test results (see App\Enums\MedicalResult). Old
        // results are kept as history; only App\Services\MedicalService writes here.
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_entry_id')->constrained('passport_entries')->restrictOnDelete();
            $table->date('medical_date')->index();
            $table->string('result', 10)->index();
            // Set only for fit results: medical_date plus config medical.validity_months.
            $table->date('valid_until')->nullable()->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Finds a passport's latest record (also serves the foreign key).
            $table->index(['passport_entry_id', 'medical_date', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
