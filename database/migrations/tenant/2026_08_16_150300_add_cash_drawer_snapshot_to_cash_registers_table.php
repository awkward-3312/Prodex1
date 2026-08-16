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
            if (! Schema::hasColumn('cash_registers', 'cash_drawer_id')) {
                $table->unsignedInteger('cash_drawer_id')->nullable()->after('warehouse_id')->index();
            }
            if (! Schema::hasColumn('cash_registers', 'cash_drawer_name_snapshot')) {
                $table->string('cash_drawer_name_snapshot')->nullable()->after('warehouse_name_snapshot');
            }
            if (! Schema::hasColumn('cash_registers', 'cash_drawer_code_snapshot')) {
                $table->string('cash_drawer_code_snapshot', 64)->nullable()->after('cash_drawer_name_snapshot');
            }
        });

        if (Schema::hasTable('cash_drawers')) {
            $registers = DB::table('cash_registers')->whereNull('cash_drawer_id')->get(['id', 'warehouse_id']);
            foreach ($registers as $register) {
                $drawer = DB::table('cash_drawers')
                    ->where('warehouse_id', $register->warehouse_id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('is_active')
                    ->orderBy('id')
                    ->first(['id', 'name', 'code']);

                if ($drawer) {
                    DB::table('cash_registers')->where('id', $register->id)->update([
                        'cash_drawer_id' => $drawer->id,
                        'cash_drawer_name_snapshot' => $drawer->name,
                        'cash_drawer_code_snapshot' => $drawer->code,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_registers')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('cash_registers', 'cash_drawer_code_snapshot') ? 'cash_drawer_code_snapshot' : null,
            Schema::hasColumn('cash_registers', 'cash_drawer_name_snapshot') ? 'cash_drawer_name_snapshot' : null,
            Schema::hasColumn('cash_registers', 'cash_drawer_id') ? 'cash_drawer_id' : null,
        ]));

        if (! empty($columns)) {
            Schema::table('cash_registers', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
