<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) return;

        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'branch_id')) {
                $table->integer('branch_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sales', 'inventory_location_id')) {
                $table->integer('inventory_location_id')->nullable()->index();
            }
            if (! Schema::hasColumn('sales', 'cash_drawer_id')) {
                $table->integer('cash_drawer_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) return;

        $columns = array_values(array_filter([
            Schema::hasColumn('sales', 'cash_drawer_id') ? 'cash_drawer_id' : null,
            Schema::hasColumn('sales', 'inventory_location_id') ? 'inventory_location_id' : null,
            Schema::hasColumn('sales', 'branch_id') ? 'branch_id' : null,
        ]));

        if ($columns) {
            Schema::table('sales', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
