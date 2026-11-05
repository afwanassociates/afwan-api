<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The passport entry serial number (1, 2, 3, …). Existing entries are
     * numbered in the order they were added (by id), deleted ones included,
     * so every number stays unique and none is reused. Only the new column is
     * written; no other data changes.
     */
    public function up(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->unsignedInteger('entry_no')->nullable()->after('id');
        });

        $next = 1;
        DB::table('passport_entries')->select('id')->chunkById(500, function ($rows) use (&$next) {
            foreach ($rows as $row) {
                DB::table('passport_entries')->where('id', $row->id)->update(['entry_no' => $next++]);
            }
        });

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->unsignedInteger('entry_no')->nullable(false)->change();
            $table->unique('entry_no');
        });
    }

    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropUnique(['entry_no']);
            $table->dropColumn('entry_no');
        });
    }
};
