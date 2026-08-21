<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_locations')) {
            Schema::create('inventory_locations', function (Blueprint $table) {
                // Keep signed INT identifiers for compatibility with the historical
                // tenant schema (branches/warehouses use integer('id', true)).
                $table->integer('id', true);
                $table->integer('branch_id')->nullable()->index();
                $table->integer('warehouse_id')->nullable()->index();
                $table->string('code', 64)->index();
                $table->string('name', 192);
                $table->string('type', 40)->default('storage')->index();
                $table->boolean('is_sellable')->default(false)->index();
                $table->boolean('is_default_sales')->default(false)->index();
                $table->boolean('is_quarantine')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps(6);
                $table->softDeletes();

                $table->index(['branch_id', 'is_active'], 'inventory_locations_branch_active');
                $table->index(['warehouse_id', 'is_active'], 'inventory_locations_warehouse_active');
            });
        }

        // Do not use ->after(...): controlled upgrades must remain safe even on
        // older tenant schemas whose column order differs from fresh installs.
        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'default_inventory_location_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->integer('default_inventory_location_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'default_inventory_location_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->integer('default_inventory_location_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'default_inventory_location_id')) {
            Schema::table('warehouses', fn (Blueprint $table) => $table->dropColumn('default_inventory_location_id'));
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'default_inventory_location_id')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('default_inventory_location_id'));
        }

        Schema::dropIfExists('inventory_locations');
    }
};
