<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An owner's designation (chairman, managing director, director). Empty
     * for clients and for owners added before it was asked for.
     */
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('designation', 30)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
