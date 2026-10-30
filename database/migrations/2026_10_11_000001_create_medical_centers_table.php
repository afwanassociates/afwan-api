<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The medical centres staff send passports to.
     *
     * @var list<string>
     */
    protected array $centers = [
        'SKN Health Services',
        'Meem Medical Center',
        'Catharsis Medical Center',
        'Zain Medical Center',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Admin-managed list for the medical form's dropdown.
        Schema::create('medical_centers', function (Blueprint $table) {
            $table->id();
            // Unique across all rows, soft-deleted ones included.
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Fill it here (not in a seeder) so production gets it with a normal migrate.
        $now = now();

        DB::table('medical_centers')->insert(array_map(fn (string $name) => [
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->centers));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_centers');
    }
};
