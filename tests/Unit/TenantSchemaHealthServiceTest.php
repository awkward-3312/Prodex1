<?php

namespace Tests\Unit;

use App\Services\TenantSchemaHealthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantSchemaHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.tenant' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        DB::purge('tenant');
        DB::connection('tenant')->getPdo();
    }

    public function test_it_reports_missing_store_credit_transactions_as_schema_requirement(): void
    {
        $this->createModernTenantSchema(false);

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertContains('Falta tabla: store_credit_voucher_transactions', $missing);
    }

    public function test_it_reports_no_missing_requirements_when_modern_schema_exists(): void
    {
        $this->createModernTenantSchema(true);

        $missing = app(TenantSchemaHealthService::class)->missingRequirements();

        $this->assertSame([], $missing);
    }

    private function createModernTenantSchema(bool $withStoreCreditTransactions): void
    {
        Schema::connection('tenant')->create('cash_registers', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('difference', 15, 2)->nullable();
            $table->json('counted_denominations')->nullable();
            $table->json('sales_by_payment_method')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('cash_difference', 15, 2)->nullable();
            $table->decimal('card_system_total', 15, 2)->nullable();
            $table->decimal('card_terminal_total', 15, 2)->nullable();
            $table->decimal('card_difference', 15, 2)->nullable();
            $table->string('card_batch_number')->nullable();
            $table->string('card_reference')->nullable();
            $table->text('card_notes')->nullable();
            $table->decimal('transfer_total', 15, 2)->nullable();
            $table->boolean('transfers_verified')->default(false);
            $table->text('transfer_notes')->nullable();
            $table->decimal('cash_withdrawn_at_close', 15, 2)->nullable();
            $table->decimal('next_opening_float', 15, 2)->nullable();
            $table->json('closing_snapshot')->nullable();
            $table->string('register_number_snapshot')->nullable();
            $table->unsignedInteger('opened_by_user_id_snapshot')->nullable();
            $table->string('opened_by_user_name_snapshot')->nullable();
            $table->unsignedInteger('closed_by_user_id')->nullable();
            $table->string('closed_by_user_name_snapshot')->nullable();
            $table->unsignedInteger('warehouse_id_snapshot')->nullable();
            $table->string('warehouse_name_snapshot')->nullable();
            $table->unsignedInteger('cash_drawer_id')->nullable();
            $table->string('cash_drawer_name_snapshot')->nullable();
            $table->string('cash_drawer_code_snapshot', 64)->nullable();
            $table->string('tenant_id_snapshot')->nullable();
            $table->date('opened_date_snapshot')->nullable();
            $table->time('opened_time_snapshot')->nullable();
            $table->date('closed_date_snapshot')->nullable();
            $table->time('closed_time_snapshot')->nullable();
            $table->unsignedInteger('session_duration_seconds')->nullable();
            $table->string('closing_status', 20)->nullable();
        });

        Schema::connection('tenant')->create('cash_drawers', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::connection('tenant')->create('user_operational_assignments', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::connection('tenant')->create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('default_warehouse_id')->nullable();
            $table->unsignedInteger('default_cash_drawer_id')->nullable();
        });

        Schema::connection('tenant')->create('store_credit_vouchers', function (Blueprint $table) {
            $table->increments('id');
        });

        if ($withStoreCreditTransactions) {
            Schema::connection('tenant')->create('store_credit_voucher_transactions', function (Blueprint $table) {
                $table->increments('id');
            });
        }

        Schema::connection('tenant')->create('sales', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('store_credit_amount', 15, 2)->default(0);
        });

        Schema::connection('tenant')->create('sale_returns', function (Blueprint $table) {
            $table->increments('id');
            $table->string('refund_mode', 32)->nullable();
            $table->integer('store_credit_voucher_id')->nullable();
            $table->decimal('store_credit_amount', 15, 2)->default(0);
        });

        $this->addRequirementsAddedAfterTheOriginalFixture();
    }

    /**
     * TenantSchemaHealthService fue ganando requisitos (sucursales, ubicaciones, SAR, asistencia, logística de
     * transferencias, kardex...) y este fixture de "esquema moderno" no se actualizó, por lo que el test dejó de
     * representar su contrato: "si el esquema moderno existe, no falta nada". Aquí se completan esos requisitos.
     * Solo importa que la tabla/columna exista (el servicio usa hasTable/hasColumn); el tipo no se comprueba.
     */
    private function addRequirementsAddedAfterTheOriginalFixture(): void
    {
        $schema = Schema::connection('tenant');

        $tables = [
            'cash_register_operations' => ['operation_uuid', 'cash_register_id', 'user_id', 'operation_type', 'amount', 'notes', 'payload_fingerprint', 'source'],
            'user_branches' => ['user_id', 'branch_id'],
            'user_inventory_locations' => ['user_id', 'inventory_location_id'],
            'sar_fiscal_profiles' => ['invoice_settings'],
            'sar_points_of_issue' => [],
            'sar_authorizations' => [],
            'sar_fiscal_documents' => [],
            'attendance_devices' => [],
            'attendance_employee_identifiers' => [],
            'attendance_punches' => [],
            'transfer_receipts' => ['request_token', 'inventory_location_id'],
            'transfer_receipt_items' => [],
            'transfer_receipt_item_batches' => [],
            'transfer_discrepancies' => ['resolution_code', 'resolution_reference', 'resolution_notes', 'resolution_status', 'resolved_at', 'resolved_by_user_id'],
            'transfer_quarantine_stock' => ['inventory_location_id'],
            'transfer_events' => [],
            'transfer_notifications' => [],
            'transfer_detail_serials' => ['transfer_detail_id', 'product_serial_id', 'transfer_receipt_item_id', 'status', 'issue_type', 'received_at'],
            'transfer_receipt_item_batch_issues' => ['transfer_receipt_item_id', 'transfer_detail_batch_id', 'source_batch_id', 'destination_batch_id', 'inventory_location_id', 'issue_type', 'quantity', 'resolved_quantity', 'resolution_status', 'resolution_code', 'resolved_at'],
            'branches' => ['code', 'name', 'type', 'manager_employee_id', 'default_warehouse_id', 'default_inventory_location_id', 'is_active'],
            'inventory_locations' => ['branch_id', 'warehouse_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales', 'is_quarantine', 'is_active'],
            'inventory_location_stocks' => ['inventory_location_id', 'product_id', 'product_variant_id', 'variant_key', 'quantity', 'reserved_quantity', 'manage_stock'],
            'inventory_location_movements' => ['movement_type', 'product_id', 'product_variant_id', 'from_inventory_location_id', 'to_inventory_location_id', 'quantity', 'user_id', 'reference_type', 'reference_id', 'idempotency_key', 'idempotency_fingerprint', 'notes', 'metadata'],
            'inventory_transition_states' => ['warehouse_id', 'inventory_location_id', 'mode', 'status', 'mismatch_count', 'last_audited_at', 'last_reconciled_at', 'shadow_enabled_at', 'metadata'],
        ];

        foreach ($tables as $table => $columns) {
            $schema->create($table, function (Blueprint $t) use ($columns) {
                $t->increments('id');
                foreach ($columns as $column) {
                    $t->string($column)->nullable();
                }
            });
        }

        foreach ([
            'cash_drawers' => ['branch_id', 'inventory_location_id'],
            'users' => ['default_branch_id', 'default_inventory_location_id', 'employee_id'],
            'user_operational_assignments' => [
                'default_branch_id_snapshot', 'default_branch_name_snapshot',
                'default_inventory_location_id_snapshot', 'default_inventory_location_name_snapshot',
                'temporary_branch_id', 'temporary_branch_name_snapshot',
                'temporary_inventory_location_id', 'temporary_inventory_location_name_snapshot',
            ],
            'sales' => ['fiscal_exemption_data', 'branch_id', 'inventory_location_id', 'cash_drawer_id', 'inventory_effect_snapshot', 'quotation_id'],
            'sale_returns' => ['branch_id', 'inventory_location_id', 'cash_drawer_id', 'inventory_effect_snapshot'],
        ] as $table => $columns) {
            $schema->table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->string($column)->nullable();
                }
            });
        }
    }
}
