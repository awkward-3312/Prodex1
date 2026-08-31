<?php

namespace App\Console\Commands;

use App\Services\LegacyInventoryReconciliationService;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProdexInventoryReconcile extends Command
{
    protected $signature = 'prodex:inventory-reconcile
        {--tenants=* : Tenant IDs to inspect. Defaults to all tenants.}
        {--warehouse= : Optional warehouse/CD ID inside each selected tenant.}
        {--plan : READ-ONLY. Print the per-product incremental reconciliation plan (delta = legacy - location) for warehouses whose MAIN already holds stock.}
        {--apply : Create default CD locations and backfill legacy product_warehouse quantities (only from an EMPTY MAIN).}';

    protected $description = 'Audit, plan (read-only) or safely backfill legacy warehouse stock into the new inventory-location engine.';

    public function handle(): int
    {
        $tenantIds = array_filter((array) $this->option('tenants'));
        $query = Tenant::query()->orderBy('id');
        if ($tenantIds) $query->whereIn('id', $tenantIds);

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $plan = (bool) $this->option('plan');
        if ($apply && $plan) {
            $this->error('Usa --plan (sólo lectura) o --apply, no ambos.');
            return self::FAILURE;
        }
        $warehouseFilter = $this->option('warehouse');
        $summary = ['tenants' => $tenants->count(), 'warehouses' => 0, 'reconciled' => 0, 'differences' => 0, 'failed' => 0];

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->info("Tenant {$tenant->id}");

            try {
                tenancy()->initialize($tenant);

                if (! Schema::connection('tenant')->hasTable('inventory_locations')
                    || ! Schema::connection('tenant')->hasTable('inventory_location_stocks')
                    || ! Schema::connection('tenant')->hasTable('inventory_location_movements')) {
                    $summary['failed']++;
                    $this->error('  Falta infraestructura de inventario. Ejecuta primero: php artisan prodex:tenant-upgrade');
                    continue;
                }

                $service = app(LegacyInventoryReconciliationService::class);
                $warehouseIds = \App\Models\Warehouse::whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

                if ($warehouseFilter !== null && $warehouseFilter !== '') {
                    $warehouseIds = array_values(array_filter($warehouseIds, fn ($id) => $id === (int) $warehouseFilter));
                }

                foreach ($warehouseIds as $warehouseId) {
                    $summary['warehouses']++;
                    try {
                        if ($plan) {
                            $p = $service->planIncremental($warehouseId);
                            $this->line(sprintf(
                                '  Warehouse/CD %d (%s): plan incremental → ADD %d (delta %.3f) | MANUAL_REVIEW %d',
                                $warehouseId, $p['warehouse_name'], $p['add_count'], $p['add_total_delta'], $p['manual_review_count']
                            ));
                            foreach ($p['plan'] as $r) {
                                $this->line(sprintf(
                                    '    prod %d / var %s | legacy %.3f | location %.3f | delta %+.3f | %s%s',
                                    $r['product_id'], $r['product_variant_id'] ?? 'simple',
                                    $r['legacy'], $r['location'], $r['delta'], $r['action'],
                                    $r['reasons'] ? ' ('.implode(',', $r['reasons']).')' : ''
                                ));
                            }
                            if ($p['manual_review_count'] > 0) $summary['differences']++;
                            continue;
                        }

                        $result = $apply
                            ? $service->backfillWarehouse($warehouseId)
                            : $service->auditWarehouse($warehouseId);

                        $status = $result['is_reconciled'] ? 'OK' : 'PENDIENTE';
                        $this->line(sprintf(
                            '  Warehouse/CD %d (%s): %s | legacy %.3f | locations %.3f | diferencias %d',
                            $warehouseId,
                            $result['warehouse_name'],
                            $status,
                            $result['legacy_total'],
                            $result['location_total'],
                            count($result['differences'])
                        ));

                        if (! empty($result['negative_legacy_rows'])) {
                            $this->warn('    Cantidades negativas detectadas: '.count($result['negative_legacy_rows']));
                        }

                        if (! empty($result['batch_or_serial_products'])) {
                            $this->warn('    Productos con lote o serie/IMEI (no aptos para backfill automático): '
                                .count($result['batch_or_serial_products']));
                        }

                        if ($result['is_reconciled']) {
                            $summary['reconciled']++;
                        } else {
                            $summary['differences']++;
                            foreach (array_slice($result['differences'], 0, 5) as $diff) {
                                $this->line(sprintf(
                                    '    Producto %d / variante %s: legacy %.3f vs location %.3f',
                                    $diff['product_id'],
                                    $diff['product_variant_id'] ?? 'simple',
                                    $diff['legacy_quantity'],
                                    $diff['location_quantity']
                                ));
                            }
                        }
                    } catch (Throwable $e) {
                        $summary['failed']++;
                        $this->error("  Warehouse/CD {$warehouseId}: ".$e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->error('  Failed: '.$e->getMessage());
            } finally {
                if (function_exists('tenancy') && tenancy()->initialized) tenancy()->end();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d tenants, %d warehouses/CD, %d reconciled, %d pending differences, %d failures.',
            $summary['tenants'],
            $summary['warehouses'],
            $summary['reconciled'],
            $summary['differences'],
            $summary['failed']
        ));

        if ($plan) {
            $this->comment('Modo plan: sólo lectura, no se modificó inventario. ADD = seguro sumar el delta; MANUAL_REVIEW = requiere revisión.');
        } elseif (! $apply) {
            $this->comment('Modo auditoría: no se modificó inventario. Usa --apply únicamente después de revisar el resultado.');
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
