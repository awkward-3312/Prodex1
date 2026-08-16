<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers') || Schema::hasColumn('cash_registers', 'counted_denominations')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->json('counted_denominations')->nullable()->after('difference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('cash_registers') || ! Schema::hasColumn('cash_registers', 'counted_denominations')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropColumn('counted_denominations');
        });
    }
};
