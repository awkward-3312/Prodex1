<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_registers', 'counted_denominations')) {
                $table->json('counted_denominations')->nullable()->after('closing_balance');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            if (Schema::hasColumn('cash_registers', 'counted_denominations')) {
                $table->dropColumn('counted_denominations');
            }
        });
    }
};
