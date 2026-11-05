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
        // The company's country is where the employer is, not the passport's country.
        Schema::table('companies', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('name')->index();
        });

        // Existing companies get the default company country (Malaysia).
        $default = DB::table('app_settings')->where('key', 'default_company_country_code')->value('value') ?? 'MY';

        DB::table('companies')->whereNull('country_code')->update(['country_code' => $default]);

        Schema::table('companies', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable(false)->change();
            $table->foreign('country_code')->references('code')->on('countries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
            $table->dropIndex(['country_code']);
            $table->dropColumn('country_code');
        });
    }
};
