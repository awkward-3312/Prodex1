<?php

namespace App\Services;

use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TenantSchemaHealthService
{
    public const CONTROLLED_MIGRATIONS = [
        'database/migrations/tenant/2026_08_16_023901_add_counted_denominations_to_cash_registers_table.php',
        'database/migrations/tenant/2026_08_16_120000_extend_cash_register_closing_audit_fields.php',
        'database/migrations/tenant/2026_08_16_130000_add_session_identity_snapshot_to_cash_registers_table.php',
        'database/migrations/tenant/2026_08_16_140000_create_store_credit_vouchers_table.php',
        'database/migrations/tenant/2026_08_16_140100_create_store_credit_voucher_transactions_table.php',
        'database/migrations/tenant/2026_08_16_140200_add_store_credit_columns_to_sales_and_returns.php',
        'database/migrations/tenant/2026_08_16_150000_create_cash_drawers_table.php',
        'database/migrations/tenant/2026_08_16_150100_add_operational_defaults_to_users_table.php',
        'database/migrations/tenant/2026_08_16_150200_create_user_operational_assignments_table.php',
        'database/migrations/tenant/2026_08_16_150300_add_cash_drawer_snapshot_to_cash_registers_table.php',
        'database/migrations/tenant/2026_08_16_150400_seed_operational_assignment_permissions.php',
        'database/migrations/tenant/2026_08_18_020000_create_sar_fiscal_tables.php',
        'database/migrations/tenant/2026_08_19_070000_normalize_legacy_store_settings_to_spanish.php',
        'database/migrations/tenant/2026_08_19_210500_add_sar_tax_classification_fields.php',
        'database/migrations/tenant/2026_08_19_214500_add_invoice_settings_to_sar_fiscal_profiles.php',
        'database/migrations/tenant/2026_08_19_220000_backfill_honduras_product_fiscal_tax.php',
        'database/migrations/tenant/2026_08_20_100000_add_receipt_presentation_fields_to_pos_settings.php',
        'database/migrations/tenant/2026_08_20_220000_create_attendance_devices_table.php',
        'database/migrations/tenant/2026_08_20_220100_create_attendance_employee_identifiers_table.php',
        'database/migrations/tenant/2026_08_20_220200_create_attendance_punches_table.php',
        'database/migrations/tenant/2026_08_20_220300_add_source_to_attendances_table.php',
        'database/migrations/tenant/2026_08_20_220000_add_transfer_logistics_receiving.php',
        'database/migrations/tenant/2026_08_20_220100_add_transfer_receipt_batch_allocations.php',
        'database/migrations/tenant/2026_08_20_220200_add_transfer_discrepancy_resolution_workflow.php',
        'database/migrations/tenant/2026_08_20_220300_normalize_pending_transfer_logistics.php',
        'database/migrations/tenant/2026_08_20_220400_add_transfer_receipt_idempotency.php',
        'database/migrations/tenant/2026_08_20_220500_backfill_existing_transfer_logistics.php',
        'database/migrations/tenant/2026_08_21_090000_add_organization_structure_foundation.php',
        'database/migrations/tenant/2026_08_21_150000_create_inventory_locations_foundation.php',
        'database/migrations/tenant/2026_08_21_160000_create_inventory_location_stock_engine.php',
        'database/migrations/tenant/2026_08_21_170000_create_inventory_transition_states.php',
        'database/migrations/tenant/2026_08_21_180000_add_branch_inventory_scope_to_users.php',
    ];

    public function checkTenant(Tenant $tenant): array
    {
        $tenant->loadMissing('domains');
        $creds = $tenant->getEffectiveDatabaseCredentials();

        $result = [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->company_name ?? $tenant->id,
            'domain' => optional($tenant->domains->first())->domain,
            'database' => $creds['database'] ?? null,
            'connectivity' => 'error',
            'schema_status' => 'unknown',
            'status' => 'error',
            'status_label' => 'Error',
            'missing' => [],
            'last_error' => null,
        ];

        try {
            tenancy()->initialize($tenant);
            DB::connection('tenant')->getPdo();
            $result['connectivity'] = 'ok';
            $result['missing'] = $this->missingRequirements();
            $result['schema_status'] = empty($result['missing']) ? 'updated' : 'outdated';
            $result['status'] = empty($result['missing']) ? 'healthy' : 'warning';
            $result['status_label'] = empty($result['missing']) ? 'Saludable' : 'Requiere actualización';
        } catch (Throwable $e) {
            $result['last_error'] = $e->getMessage();
        } finally {
            if (function_exists('tenancy') && tenancy()->initialized) tenancy()->end();
        }

        return $result;
    }

    public function missingRequirements(): array
    {
        $schema = Schema::connection('tenant');
        $missing = [];

        $this->requireTable($schema, $missing, 'cash_registers');
        $this->requireColumns($schema, $missing, 'cash_registers', [
            'counted_denominations', 'sales_by_payment_method', 'expected_cash', 'counted_cash', 'cash_difference',
            'card_system_total', 'card_terminal_total', 'card_difference', 'card_batch_number', 'card_reference',
            'card_notes', 'transfer_total', 'transfers_verified', 'transfer_notes', 'cash_withdrawn_at_close',
            'next_opening_float', 'closing_snapshot', 'register_number_snapshot', 'opened_by_user_id_snapshot',
            'opened_by_user_name_snapshot', 'closed_by_user_id', 'closed_by_user_name_snapshot', 'warehouse_id_snapshot',
            'warehouse_name_snapshot', 'tenant_id_snapshot', 'opened_date_snapshot', 'opened_time_snapshot',
            'closed_date_snapshot', 'closed_time_snapshot', 'session_duration_seconds', 'closing_status', 'cash_drawer_id',
            'cash_drawer_name_snapshot', 'cash_drawer_code_snapshot',
        ]);
        $this->requireTable($schema, $missing, 'cash_drawers');
        $this->requireColumns($schema, $missing, 'cash_drawers', ['branch_id', 'inventory_location_id']);
        $this->requireTable($schema, $missing, 'user_operational_assignments');
        $this->requireColumns($schema, $missing, 'users', [
            'default_warehouse_id', 'default_branch_id', 'default_inventory_location_id', 'default_cash_drawer_id',
        ]);
        $this->requireTable($schema, $missing, 'user_branches');
        $this->requireColumns($schema, $missing, 'user_branches', ['user_id', 'branch_id']);
        $this->requireTable($schema, $missing, 'user_inventory_locations');
        $this->requireColumns($schema, $missing, 'user_inventory_locations', ['user_id', 'inventory_location_id']);
        $this->requireColumns($schema, $missing, 'user_operational_assignments', [
            'default_branch_id_snapshot', 'default_branch_name_snapshot',
            'default_inventory_location_id_snapshot', 'default_inventory_location_name_snapshot',
            'temporary_branch_id', 'temporary_branch_name_snapshot',
            'temporary_inventory_location_id', 'temporary_inventory_location_name_snapshot',
        ]);

        $this->requireTable($schema, $missing, 'store_credit_vouchers');
        $this->requireTable($schema, $missing, 'store_credit_voucher_transactions');
        $this->requireColumns($schema, $missing, 'sales', ['store_credit_amount', 'fiscal_exemption_data']);
        $this->requireColumns($schema, $missing, 'sale_returns', ['refund_mode', 'store_credit_voucher_id', 'store_credit_amount']);

        $this->requireTable($schema, $missing, 'sar_fiscal_profiles');
        $this->requireTable($schema, $missing, 'sar_points_of_issue');
        $this->requireTable($schema, $missing, 'sar_authorizations');
        $this->requireTable($schema, $missing, 'sar_fiscal_documents');
        $this->requireColumns($schema, $missing, 'sar_fiscal_profiles', ['invoice_settings']);
        $this->requireColumns($schema, $missing, 'products', ['fiscal_tax_category']);
        $this->requireColumns($schema, $missing, 'sale_details', ['fiscal_tax_category', 'fiscal_tax_rate']);
        $this->requireColumns($schema, $missing, 'clients', [
            'identification_type', 'identification_number', 'sar_registry_number', 'exoneration_registry_number',
        ]);

        $this->requireColumns($schema, $missing, 'pos_settings', [
            'receipt_header_alignment', 'receipt_fiscal_alignment', 'receipt_customer_alignment',
            'receipt_items_alignment', 'receipt_totals_alignment', 'receipt_footer_alignment', 'receipt_qr_alignment',
            'receipt_font_size', 'receipt_density', 'receipt_separator',
        ]);

        $this->requireTable($schema, $missing, 'attendance_devices');
        $this->requireTable($schema, $missing, 'attendance_employee_identifiers');
        $this->requireTable($schema, $missing, 'attendance_punches');
        $this->requireColumns($schema, $missing, 'attendances', ['source', 'source_reference']);

        $this->requireColumns($schema, $missing, 'transfers', [
            'receiving_token', 'logistics_status', 'dispatched_at', 'dispatched_by_user_id',
            'received_at', 'received_by_user_id',
        ]);
        $this->requireTable($schema, $missing, 'transfer_receipts');
        $this->requireColumns($schema, $missing, 'transfer_receipts', ['request_token']);
        $this->requireTable($schema, $missing, 'transfer_receipt_items');
        $this->requireTable($schema, $missing, 'transfer_receipt_item_batches');
        $this->requireTable($schema, $missing, 'transfer_discrepancies');
        $this->requireColumns($schema, $missing, 'transfer_discrepancies', [
            'resolution_code', 'resolution_reference', 'resolution_notes', 'resolution_status',
            'resolved_at', 'resolved_by_user_id',
        ]);
        $this->requireTable($schema, $missing, 'transfer_quarantine_stock');
        $this->requireTable($schema, $missing, 'transfer_events');
        $this->requireTable($schema, $missing, 'transfer_notifications');

        $this->requireTable($schema, $missing, 'branches');
        $this->requireColumns($schema, $missing, 'branches', [
            'code', 'name', 'type', 'manager_employee_id', 'default_warehouse_id', 'default_inventory_location_id', 'is_active',
        ]);
        $this->requireColumns($schema, $missing, 'warehouses', ['branch_id', 'default_inventory_location_id']);
        $this->requireColumns($schema, $missing, 'employees', ['branch_id']);
        $this->requireColumns($schema, $missing, 'users', ['employee_id']);
        $this->requireColumns($schema, $missing, 'designations', [
            'code', 'description', 'is_system_default', 'is_active', 'suggested_role_key',
        ]);

        $this->requireTable($schema, $missing, 'inventory_locations');
        $this->requireColumns($schema, $missing, 'inventory_locations', [
            'branch_id', 'warehouse_id', 'code', 'name', 'type', 'is_sellable',
            'is_default_sales', 'is_quarantine', 'is_active',
        ]);

        $this->requireTable($schema, $missing, 'inventory_location_stocks');
        $this->requireColumns($schema, $missing, 'inventory_location_stocks', [
            'inventory_location_id', 'product_id', 'product_variant_id', 'variant_key',
            'quantity', 'reserved_quantity', 'manage_stock',
        ]);
        $this->requireTable($schema, $missing, 'inventory_location_movements');
        $this->requireColumns($schema, $missing, 'inventory_location_movements', [
            'movement_type', 'product_id', 'product_variant_id', 'from_inventory_location_id',
            'to_inventory_location_id', 'quantity', 'user_id', 'reference_type',
            'reference_id', 'idempotency_key', 'idempotency_fingerprint', 'notes', 'metadata',
        ]);

        $this->requireTable($schema, $missing, 'inventory_transition_states');
        $this->requireColumns($schema, $missing, 'inventory_transition_states', [
            'warehouse_id', 'inventory_location_id', 'mode', 'status', 'mismatch_count',
            'last_audited_at', 'last_reconciled_at', 'shadow_enabled_at', 'metadata',
        ]);

        return $missing;
    }

    private function requireTable($schema, array &$missing, string $table): void
    {
        if (! $schema->hasTable($table)) $missing[] = "Falta tabla: {$table}";
    }

    private function requireColumns($schema, array &$missing, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) return;
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) $missing[] = "Falta columna: {$table}.{$column}";
        }
    }
}
