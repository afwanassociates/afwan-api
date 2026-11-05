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
        // For the passport list's reference filter. The (type, name) unique index
        // starts with type, so it does not serve lookups by name alone.
        if (! Schema::hasIndex('references', ['name'])) {
            Schema::table('references', function (Blueprint $table) {
                $table->index('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('references', ['name'])) {
            Schema::table('references', function (Blueprint $table) {
                $table->dropIndex(['name']);
            });
        }
    }
};
