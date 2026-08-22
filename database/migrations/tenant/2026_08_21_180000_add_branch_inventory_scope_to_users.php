<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'default_branch_id')) {
                    $table->integer('default_branch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('users', 'default_inventory_location_id')) {
                    $table->integer('default_inventory_location_id')->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('user_branches')) {
            Schema::create('user_branches', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id')->index();
                $table->integer('branch_id')->index();
                $table->timestamps(6);
                $table->unique(['user_id', 'branch_id'], 'user_branches_user_branch_unique');
            });
        }

        if (! Schema::hasTable('user_inventory_locations')) {
            Schema::create('user_inventory_locations', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id')->index();
                $table->integer('inventory_location_id')->index();
                $table->timestamps(6);
                $table->unique(['user_id', 'inventory_location_id'], 'user_inventory_locations_user_location_unique');
            });
        }

        if (Schema::hasTable('user_operational_assignments')) {
            Schema::table('user_operational_assignments', function (Blueprint $table) {
                $columns = [
                    'default_branch_id_snapshot' => fn () => $table->integer('default_branch_id_snapshot')->nullable()->index(),
                    'default_branch_name_snapshot' => fn () => $table->string('default_branch_name_snapshot', 191)->nullable(),
                    'default_inventory_location_id_snapshot' => fn () => $table->integer('default_inventory_location_id_snapshot')->nullable()->index('uoa_default_inventory_location_idx'),
                    'default_inventory_location_name_snapshot' => fn () => $table->string('default_inventory_location_name_snapshot', 191)->nullable(),
                    'temporary_branch_id' => fn () => $table->integer('temporary_branch_id')->nullable()->index(),
                    'temporary_branch_name_snapshot' => fn () => $table->string('temporary_branch_name_snapshot', 191)->nullable(),
                    'temporary_inventory_location_id' => fn () => $table->integer('temporary_inventory_location_id')->nullable()->index('uoa_temporary_inventory_location_idx'),
                    'temporary_inventory_location_name_snapshot' => fn () => $table->string('temporary_inventory_location_name_snapshot', 191)->nullable(),
                ];

                foreach ($columns as $name => $definition) {
                    if (! Schema::hasColumn('user_operational_assignments', $name)) {
                        $definition();
                    }
                }
            });
        }

        if (Schema::hasTable('cash_drawers')) {
            Schema::table('cash_drawers', function (Blueprint $table) {
                if (! Schema::hasColumn('cash_drawers', 'branch_id')) {
                    $table->integer('branch_id')->nullable()->index();
                }
                if (! Schema::hasColumn('cash_drawers', 'inventory_location_id')) {
                    $table->integer('inventory_location_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_drawers')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('cash_drawers', 'inventory_location_id') ? 'inventory_location_id' : null,
                Schema::hasColumn('cash_drawers', 'branch_id') ? 'branch_id' : null,
            ]));
            if ($columns) Schema::table('cash_drawers', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        if (Schema::hasTable('user_operational_assignments')) {
            $names = [
                'default_branch_id_snapshot', 'default_branch_name_snapshot',
                'default_inventory_location_id_snapshot', 'default_inventory_location_name_snapshot',
                'temporary_branch_id', 'temporary_branch_name_snapshot',
                'temporary_inventory_location_id', 'temporary_inventory_location_name_snapshot',
            ];
            $columns = array_values(array_filter($names, fn ($name) => Schema::hasColumn('user_operational_assignments', $name)));
            if ($columns) Schema::table('user_operational_assignments', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        Schema::dropIfExists('user_inventory_locations');
        Schema::dropIfExists('user_branches');

        if (Schema::hasTable('users')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'default_inventory_location_id') ? 'default_inventory_location_id' : null,
                Schema::hasColumn('users', 'default_branch_id') ? 'default_branch_id' : null,
            ]));
            if ($columns) Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
