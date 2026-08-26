<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_registers', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('user_id')->index();
            }
            if (! Schema::hasColumn('cash_registers', 'inventory_location_id')) {
                $table->unsignedBigInteger('inventory_location_id')->nullable()->after('branch_id')->index();
            }
            if (! Schema::hasColumn('cash_registers', 'branch_id_snapshot')) {
                $table->unsignedBigInteger('branch_id_snapshot')->nullable()->after('warehouse_id_snapshot');
            }
            if (! Schema::hasColumn('cash_registers', 'branch_name_snapshot')) {
                $table->string('branch_name_snapshot')->nullable()->after('branch_id_snapshot');
            }
            if (! Schema::hasColumn('cash_registers', 'inventory_location_id_snapshot')) {
                $table->unsignedBigInteger('inventory_location_id_snapshot')->nullable()->after('branch_name_snapshot');
            }
            if (! Schema::hasColumn('cash_registers', 'inventory_location_name_snapshot')) {
                $table->string('inventory_location_name_snapshot')->nullable()->after('inventory_location_id_snapshot');
            }
        });

        if (Schema::hasTable('cash_drawers')) {
            DB::statement(<<<'SQL'
                UPDATE cash_registers cr
                INNER JOIN cash_drawers cd ON cd.id = cr.cash_drawer_id
                SET
                    cr.branch_id = COALESCE(cr.branch_id, cd.branch_id),
                    cr.inventory_location_id = COALESCE(cr.inventory_location_id, cd.inventory_location_id)
                WHERE cr.cash_drawer_id IS NOT NULL
            SQL);
        }

        if (Schema::hasTable('warehouses')) {
            $hasWarehouseBranch = Schema::hasColumn('warehouses', 'branch_id');
            $hasWarehouseLocation = Schema::hasColumn('warehouses', 'default_inventory_location_id');

            if ($hasWarehouseBranch || $hasWarehouseLocation) {
                $assignments = [];
                if ($hasWarehouseBranch) {
                    $assignments[] = 'cr.branch_id = COALESCE(cr.branch_id, w.branch_id)';
                }
                if ($hasWarehouseLocation) {
                    $assignments[] = 'cr.inventory_location_id = COALESCE(cr.inventory_location_id, w.default_inventory_location_id)';
                }

                DB::statement(
                    'UPDATE cash_registers cr INNER JOIN warehouses w ON w.id = cr.warehouse_id SET '.implode(', ', $assignments).' WHERE cr.warehouse_id IS NOT NULL'
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        Schema::table('cash_registers', function (Blueprint $table) {
            foreach ([
                'inventory_location_name_snapshot',
                'inventory_location_id_snapshot',
                'branch_name_snapshot',
                'branch_id_snapshot',
                'inventory_location_id',
                'branch_id',
            ] as $column) {
                if (Schema::hasColumn('cash_registers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
