<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Countries pinned to the top of dropdowns, in this order.
     *
     * @var list<string>
     */
    protected array $pinned = ['BD', 'MY'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Admin-managed list. Countries are never deleted, only deactivated.
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_pinned')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Fill it here (not in a seeder) so production gets it with a normal migrate.
        $now = now();

        DB::table('countries')->insert(collect(config('countries'))
            ->map(fn (string $name, string $code) => [
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'is_pinned' => in_array($code, $this->pinned, true),
                'sort_order' => in_array($code, $this->pinned, true) ? array_search($code, $this->pinned, true) + 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
