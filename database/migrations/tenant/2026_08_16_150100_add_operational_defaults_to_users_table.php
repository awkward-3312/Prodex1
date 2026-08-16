<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'default_warehouse_id')) {
                $table->unsignedInteger('default_warehouse_id')->nullable()->after('is_all_warehouses')->index();
            }
            if (! Schema::hasColumn('users', 'default_cash_drawer_id')) {
                $table->unsignedInteger('default_cash_drawer_id')->nullable()->after('default_warehouse_id')->index();
            }
        });

        if (! Schema::hasTable('warehouses') || ! Schema::hasTable('cash_drawers')) {
            return;
        }

        $users = DB::table('users')->whereNull('deleted_at')->get(['id', 'is_all_warehouses', 'default_warehouse_id', 'default_cash_drawer_id']);
        foreach ($users as $user) {
            if ($user->default_warehouse_id && $user->default_cash_drawer_id) {
                continue;
            }

            $warehouseId = $user->default_warehouse_id;
            if (! $warehouseId) {
                if ((int) $user->is_all_warehouses === 1) {
                    $warehouseId = DB::table('warehouses')->whereNull('deleted_at')->orderBy('id')->value('id');
                } elseif (Schema::hasTable('user_warehouse')) {
                    $warehouseId = DB::table('user_warehouse')->where('user_id', $user->id)->orderBy('warehouse_id')->value('warehouse_id');
                }
            }

            if (! $warehouseId) {
                continue;
            }

            $drawerId = $user->default_cash_drawer_id ?: DB::table('cash_drawers')
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            DB::table('users')->where('id', $user->id)->update([
                'default_warehouse_id' => $warehouseId,
                'default_cash_drawer_id' => $drawerId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('users', 'default_cash_drawer_id') ? 'default_cash_drawer_id' : null,
            Schema::hasColumn('users', 'default_warehouse_id') ? 'default_warehouse_id' : null,
        ]));

        if (! empty($columns)) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
