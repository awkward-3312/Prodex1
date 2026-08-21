<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_returns', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sale_returns', 'inventory_location_id')) {
                $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sale_returns', 'cash_drawer_id')) {
                $table->unsignedBigInteger('cash_drawer_id')->nullable()->index();
            }
        });

        // Existing returns inherit physical context from their linked sale when
        // that sale was already created using the location-aware POS model.
        if (Schema::hasColumn('sales', 'branch_id')
            && Schema::hasColumn('sales', 'inventory_location_id')
            && Schema::hasColumn('sales', 'cash_drawer_id')) {
            DB::statement(<<<'SQL'
                UPDATE sale_returns sr
                INNER JOIN sales s ON s.id = sr.sale_id
                SET sr.branch_id = COALESCE(sr.branch_id, s.branch_id),
                    sr.inventory_location_id = COALESCE(sr.inventory_location_id, s.inventory_location_id),
                    sr.cash_drawer_id = COALESCE(sr.cash_drawer_id, s.cash_drawer_id)
                WHERE sr.sale_id IS NOT NULL
                  AND s.inventory_location_id IS NOT NULL
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            foreach (['cash_drawer_id', 'inventory_location_id', 'branch_id'] as $column) {
                if (Schema::hasColumn('sale_returns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
