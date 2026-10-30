<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Online is now the only mark. Passports marked "keyin" become "online",
     * so they keep their place in Calling and still read as a valid mark.
     * Safe to run again: there is nothing left to convert.
     */
    public function up(): void
    {
        DB::table('passport_entries')->where('entry_mode', 'keyin')->update(['entry_mode' => 'online']);
    }

    /**
     * Nothing to undo: which passports used to be "keyin" is not kept, so
     * they cannot be told apart from the ones marked Online.
     */
    public function down(): void
    {
        //
    }
};
