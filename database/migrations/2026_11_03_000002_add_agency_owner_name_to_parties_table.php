<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client (party) is an agency: the name of its owner, whose NID or
     * passport is the party's nid_passport. Empty for company owners and for
     * clients added before it was asked for.
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('agency_owner_name', 150)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('agency_owner_name');
        });
    }
};
