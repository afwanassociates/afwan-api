<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A party's receipt names the business it is for (recruitment, land, solar,
     * railway) and, for recruitment, the destination country. Empty for owner
     * receipts. Existing party receipts become recruitment for the default
     * passport country (MY when never set).
     */
    public function up(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->string('business_type', 20)->nullable()->after('receipt_type');
            $table->char('country_code', 2)->nullable()->after('business_type');
            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
        });

        $country = DB::table('app_settings')->where('key', Settings::DEFAULT_PASSPORT_COUNTRY)->value('value') ?: Settings::FALLBACK_COUNTRY;
        if (! DB::table('countries')->where('code', $country)->exists()) {
            $country = null;
        }

        DB::table('cash_receipts')
            ->whereIn('party_id', DB::table('parties')->where('type', 'client')->select('id'))
            ->update(['business_type' => 'recruitment', 'country_code' => $country]);
    }

    public function down(): void
    {
        Schema::table('cash_receipts', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
            $table->dropColumn(['business_type', 'country_code']);
        });
    }
};
