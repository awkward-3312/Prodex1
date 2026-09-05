<?php

namespace Tests\Unit;

use App\Services\TenantSchemaHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FINAL INVENTORY CLOSURE — the MS5/MS6/MS7 tenant migrations must be part of
 * the official controlled list `php artisan prodex:tenant-upgrade` applies to
 * existing tenants, and TenantSchemaHealthService::missingRequirements() must
 * actually detect their columns when absent.
 */
class InventoryNativeTenantUpgradeMigrationsTest extends TestCase
{
    public function test_controlled_migrations_include_the_ms5_ms6_ms7_september_migrations(): void
    {
        $controlled = TenantSchemaHealthService::CONTROLLED_MIGRATIONS;

        $this->assertContains(
            'database/migrations/tenant/2026_09_02_000000_add_inventory_location_to_purchases_and_returns.php',
            $controlled
        );
        $this->assertContains(
            'database/migrations/tenant/2026_09_03_000000_add_serial_native_foundation.php',
            $controlled
        );
        $this->assertContains(
            'database/migrations/tenant/2026_09_04_000000_add_inventory_effect_snapshot_to_sales_and_returns.php',
            $controlled
        );
    }

    public function test_every_controlled_migration_file_actually_exists_on_disk(): void
    {
        foreach (TenantSchemaHealthService::CONTROLLED_MIGRATIONS as $relativePath) {
            $this->assertFileExists(base_path($relativePath), "Missing controlled migration file: {$relativePath}");
        }
    }

    public function test_schema_health_flags_missing_purchase_location_native_columns(): void
    {
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();

        Schema::connection('tenant')->create('purchases', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::connection('tenant')->create('purchase_returns', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::connection('tenant')->create('sales', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::connection('tenant')->create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::connection('tenant')->create('product_serial_movements', function (Blueprint $table) {
            $table->increments('id');
        });

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertContains('Falta columna: purchases.inventory_location_id', $missing);
        $this->assertContains('Falta columna: purchases.inventory_effect_snapshot', $missing);
        $this->assertContains('Falta columna: purchase_returns.inventory_location_id', $missing);
        $this->assertContains('Falta columna: purchase_returns.inventory_effect_snapshot', $missing);
        $this->assertContains('Falta columna: sales.inventory_effect_snapshot', $missing);
        $this->assertContains('Falta columna: sale_returns.inventory_effect_snapshot', $missing);
        $this->assertContains('Falta columna: product_serial_movements.idempotency_key', $missing);
        $this->assertContains('Falta columna: product_serial_movements.idempotency_fingerprint', $missing);
    }

    public function test_schema_health_does_not_flag_those_columns_once_present(): void
    {
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();

        Schema::connection('tenant')->create('purchases', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('inventory_location_id')->nullable();
            $table->json('inventory_effect_snapshot')->nullable();
        });
        Schema::connection('tenant')->create('purchase_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('inventory_location_id')->nullable();
            $table->json('inventory_effect_snapshot')->nullable();
        });
        Schema::connection('tenant')->create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->json('inventory_effect_snapshot')->nullable();
        });
        Schema::connection('tenant')->create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->json('inventory_effect_snapshot')->nullable();
        });
        Schema::connection('tenant')->create('product_serial_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->string('idempotency_key')->nullable();
            $table->string('idempotency_fingerprint')->nullable();
        });

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertNotContains('Falta columna: purchases.inventory_location_id', $missing);
        $this->assertNotContains('Falta columna: purchases.inventory_effect_snapshot', $missing);
        $this->assertNotContains('Falta columna: purchase_returns.inventory_location_id', $missing);
        $this->assertNotContains('Falta columna: purchase_returns.inventory_effect_snapshot', $missing);
        $this->assertNotContains('Falta columna: sales.inventory_effect_snapshot', $missing);
        $this->assertNotContains('Falta columna: sale_returns.inventory_effect_snapshot', $missing);
        $this->assertNotContains('Falta columna: product_serial_movements.idempotency_key', $missing);
        $this->assertNotContains('Falta columna: product_serial_movements.idempotency_fingerprint', $missing);
    }
}
