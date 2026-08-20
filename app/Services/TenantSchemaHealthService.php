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
            if (function_exists('tenancy') && tenancy()->initialized) {
                tenancy()->end();
            }
        }

        return $result;
    }

    public function missingRequirements(): array
    {
        $schema = Schema::connection('tenant');
        $missing = [];

        $this->requireTable($schema, $missing, 'cash_registers');
        $this->requireColumns($schema, $missing, 'cash_registers', [
            'counted_denominations',
            'sales_by_payment_method',
            'expected_cash',
            'counted_cash',
            'cash_difference',
            'card_system_total',
            'card_terminal_total',
            'card_difference',
            'card_batch_number',
            'card_reference',
            'card_notes',
            'transfer_total',
            'transfers_verified',
            'transfer_notes',
            'cash_withdrawn_at_close',
            'next_opening_float',
            'closing_snapshot',
            'register_number_snapshot',
            'opened_by_user_id_snapshot',
            'opened_by_user_name_snapshot',
            'closed_by_user_id',
            'closed_by_user_name_snapshot',
            'warehouse_id_snapshot',
            'warehouse_name_snapshot',
            'tenant_id_snapshot',
            'opened_date_snapshot',
            'opened_time_snapshot',
            'closed_date_snapshot',
            'closed_time_snapshot',
            'session_duration_seconds',
            'closing_status',
            'cash_drawer_id',
            'cash_drawer_name_snapshot',
            'cash_drawer_code_snapshot',
        ]);
        $this->requireTable($schema, $missing, 'cash_drawers');
        $this->requireTable($schema, $missing, 'user_operational_assignments');
        $this->requireColumns($schema, $missing, 'users', [
            'default_warehouse_id',
            'default_cash_drawer_id',
        ]);

        $this->requireTable($schema, $missing, 'store_credit_vouchers');
        $this->requireTable($schema, $missing, 'store_credit_voucher_transactions');
        $this->requireColumns($schema, $missing, 'sales', [
            'store_credit_amount',
            'fiscal_exemption_data',
        ]);
        $this->requireColumns($schema, $missing, 'sale_returns', [
            'refund_mode',
            'store_credit_voucher_id',
            'store_credit_amount',
        ]);

        // Honduras SAR fiscal invoicing requirements.
        $this->requireTable($schema, $missing, 'sar_fiscal_profiles');
        $this->requireTable($schema, $missing, 'sar_points_of_issue');
        $this->requireTable($schema, $missing, 'sar_authorizations');
        $this->requireTable($schema, $missing, 'sar_fiscal_documents');
        $this->requireColumns($schema, $missing, 'sar_fiscal_profiles', ['invoice_settings']);
        $this->requireColumns($schema, $missing, 'products', ['fiscal_tax_category']);
        $this->requireColumns($schema, $missing, 'sale_details', ['fiscal_tax_category', 'fiscal_tax_rate']);
        $this->requireColumns($schema, $missing, 'clients', [
            'identification_type',
            'identification_number',
            'sar_registry_number',
            'exoneration_registry_number',
        ]);

        return $missing;
    }

    private function requireTable($schema, array &$missing, string $table): void
    {
        if (! $schema->hasTable($table)) {
            $missing[] = "Falta tabla: {$table}";
        }
    }

    private function requireColumns($schema, array &$missing, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                $missing[] = "Falta columna: {$table}.{$column}";
            }
        }
    }
}
