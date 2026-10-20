<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Admin-managed settings, read through App\Support\Settings.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('value', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('app_settings')->insert([
            ['key' => 'default_company_country_code', 'value' => 'MY', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'default_passport_country_code', 'value' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
