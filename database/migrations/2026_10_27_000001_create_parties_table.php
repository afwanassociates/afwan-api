<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts: parties (clients) and owners. Each type numbers its own
     * sequence, shown as the party ID (PT-0001, OW-001).
     */
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedInteger('sequence');
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('mobile', 30);
            $table->string('address', 500);
            $table->string('nid_passport', 50)->unique();
            $table->string('tin_bin', 50)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->string('opening_balance_type', 20)->default('receivable');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'sequence']);
            $table->index('name');
            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
