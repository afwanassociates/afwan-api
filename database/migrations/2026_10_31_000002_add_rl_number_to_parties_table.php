<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's recruiting licence (RL) number: required for clients by
     * the form, unique. Nullable because owners have none and existing
     * clients get theirs on their next edit.
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('rl_number', 50)->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['rl_number']);
            $table->dropColumn('rl_number');
        });
    }
};
