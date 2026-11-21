<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make sure Malaysia exists and is active, and that the default passport
     * country setting exists, defaulting to MY. Safe to run again: it never
     * overrides a default an admin has chosen.
     */
    public function up(): void
    {
        $now = now();

        if (DB::table('countries')->where('code', 'MY')->exists()) {
            DB::table('countries')->where('code', 'MY')->update(['is_active' => true, 'updated_at' => $now]);
        } else {
            DB::table('countries')->insert([
                'code' => 'MY',
                'name' => 'Malaysia',
                'is_active' => true,
                'is_pinned' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $key = Settings::DEFAULT_PASSPORT_COUNTRY;

        if (! DB::table('app_settings')->where('key', $key)->exists()) {
            DB::table('app_settings')->insert(['key' => $key, 'value' => 'MY', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('app_settings')->where('key', $key)->whereNull('value')->update(['value' => 'MY', 'updated_at' => $now]);
        }

        Cache::forget(Settings::CACHE_KEY);
    }

    /**
     * Data repair only: nothing to undo.
     */
    public function down(): void
    {
        //
    }
};
