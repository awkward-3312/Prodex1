<?php

namespace Tests\Unit;

use App\Services\TenantSchemaHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FASE 12A — cash_register_operations is the idempotency + audit ledger for
 * Mobile cash-register open/cash-in/cash-out. Must be part of the controlled
 * tenant-upgrade migration list and detected by TenantSchemaHealthService.
 */
class CashRegisterOperationsTenantUpgradeMigrationTest extends TestCase
{
    public function test_controlled_migrations_include_the_cash_register_operations_migration(): void
    {
        $this->assertContains(
            'database/migrations/tenant/2026_09_12_000000_create_cash_register_operations_table.php',
            TenantSchemaHealthService::CONTROLLED_MIGRATIONS
        );
    }

    public function test_every_controlled_migration_file_actually_exists_on_disk(): void
    {
        foreach (TenantSchemaHealthService::CONTROLLED_MIGRATIONS as $relativePath) {
            $this->assertFileExists(base_path($relativePath), "Missing controlled migration file: {$relativePath}");
        }
    }

    public function test_migration_creates_expected_columns_with_unique_operation_uuid(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_09_12_000000_create_cash_register_operations_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('cash_register_operations'));
        foreach (['operation_uuid', 'cash_register_id', 'user_id', 'operation_type', 'amount', 'notes', 'payload_fingerprint', 'source'] as $column) {
            $this->assertTrue(Schema::hasColumn('cash_register_operations', $column), "Missing column: {$column}");
        }

        DB::table('cash_register_operations')->insert([
            'operation_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'user_id' => 1,
            'operation_type' => 'open',
            'amount' => 500,
            'payload_fingerprint' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('cash_register_operations')->insert([
            'operation_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'user_id' => 2,
            'operation_type' => 'open',
            'amount' => 100,
            'payload_fingerprint' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_migration_can_be_rolled_back(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_09_12_000000_create_cash_register_operations_table.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('cash_register_operations'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('cash_register_operations'));
    }

    public function test_schema_health_flags_missing_cash_register_operations_table(): void
    {
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertContains('Falta tabla: cash_register_operations', $missing);
    }

    public function test_schema_health_does_not_flag_the_table_once_present_with_all_columns(): void
    {
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();

        Schema::connection('tenant')->create('cash_register_operations', function (Blueprint $table) {
            $table->id();
            $table->char('operation_uuid', 36)->unique();
            $table->unsignedBigInteger('cash_register_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('operation_type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->string('payload_fingerprint', 64);
            $table->string('source', 20)->default('mobile');
            $table->timestamps();
        });

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertNotContains('Falta tabla: cash_register_operations', $missing);
        foreach (['operation_uuid', 'cash_register_id', 'user_id', 'operation_type', 'amount', 'notes', 'payload_fingerprint', 'source'] as $column) {
            $this->assertNotContains("Falta columna: cash_register_operations.{$column}", $missing);
        }
    }
}
