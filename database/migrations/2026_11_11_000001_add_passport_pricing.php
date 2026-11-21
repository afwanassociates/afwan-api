<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passport prices (the payment report's due): a company's standard rate per
     * passport, a reference's own rate for a company, and each passport's price,
     * locked when the passport is entered (or its reference or company changes)
     * from the reference rate, else the company rate; or a special price set on
     * the passport. price_source: company, reference or special.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('rate', 15, 2)->nullable()->after('quota');
        });

        Schema::create('party_company_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate', 15, 2);
            $table->timestamps();

            $table->unique(['party_id', 'company_id']);
        });

        Schema::table('passport_entries', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->nullable();
            $table->string('price_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passport_entries', function (Blueprint $table) {
            $table->dropColumn(['price', 'price_source']);
        });
        Schema::dropIfExists('party_company_rates');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('rate');
        });
    }
};
