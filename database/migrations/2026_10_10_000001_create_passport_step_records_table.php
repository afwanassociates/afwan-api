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
        // Records of the steps after medical (calling, visa, bmet, flight; see
        // config/workflow.php). History is kept; only App\Services\WorkflowService
        // writes here.
        Schema::create('passport_step_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_entry_id')->constrained('passport_entries')->restrictOnDelete();
            $table->string('step_key', 20)->index();
            $table->string('status', 20)->index();
            $table->string('reference_no', 100)->nullable();
            $table->date('step_date');
            $table->date('valid_until')->nullable()->index();
            // Step-specific fields, e.g. the flight's airline and departure date.
            $table->json('details')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Finds a passport's latest record per step (also serves the foreign key).
            $table->index(['passport_entry_id', 'step_key', 'step_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passport_step_records');
    }
};
