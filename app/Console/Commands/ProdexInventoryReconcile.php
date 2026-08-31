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
        {--product= : Sólo con --apply-incremental: reconcilia quirúrgicamente un único product_id.}
        {--plan : READ-ONLY. Print the provenance-based reconciliation plan: only LEGACY_ONLY_PENDING keys are ADD candidates. snapshot_drift is diagnostic only.}
        {--apply : Create default CD locations and backfill legacy product_warehouse quantities (only from an EMPTY MAIN).}
        {--apply-incremental : Aplica UNA fila LEGACY_ONLY_PENDING validada (ADD) sumando la cantidad legacy no migrada. v1: requiere --tenants=<uno>, --warehouse=<id> y --product=<id> (sólo quirúrgico; el batch se reactivará con locking específico). Revalida provenance dentro de una transacción con lockForUpdate de las fuentes del cálculo y aborta completo si algo cambió. product_warehouse NO se toca.}';

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
        $applyIncremental = (bool) $this->option('apply-incremental');
        if (count(array_filter([$apply, $plan, $applyIncremental])) > 1) {
            $this->error('Usa sólo uno de --plan (lectura), --apply (backfill de almacén vacío) o --apply-incremental.');
            return self::FAILURE;
        }

        $warehouseFilter = $this->option('warehouse');
        $productFilter = $this->option('product');

        if ($applyIncremental) {
            if (count($tenantIds) !== 1) {
                $this->error('--apply-incremental requiere exactamente un --tenants=<tenant>.');
                return self::FAILURE;
            }
            if ($warehouseFilter === null || $warehouseFilter === '') {
                $this->error('--apply-incremental requiere --warehouse=<id>.');
                return self::FAILURE;
            }
            // v1: la escritura incremental es SÓLO quirúrgica. El batch sin
            // --product volverá con una estrategia de locking específica.
            if ($productFilter === null || $productFilter === '') {
                $this->error('--apply-incremental requiere --product=<id> (v1: sólo reconciliación quirúrgica). '
                    .'El modo batch se reactivará con locking específico. --plan (sólo lectura) sí funciona sin --product.');
                return self::FAILURE;
            }
        }
        if ($productFilter !== null && $productFilter !== '' && ! $applyIncremental) {
            $this->error('--product sólo aplica junto con --apply-incremental.');
            return self::FAILURE;
        }

        $summary = [
            'tenants' => $tenants->count(), 'warehouses' => 0,
            'reconciled' => 0, 'differences' => 0, 'failed' => 0,
            'add_candidates' => 0, 'manual_review' => 0, 'applied' => 0,
        ];

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
                                '  Warehouse/CD %d (%s): plan (provenance) → ADD %d (%.3f) | MANUAL_REVIEW %d | baseline %s | snapshot_drift %.3f (diagnóstico)',
                                $warehouseId, $p['warehouse_name'], $p['add_count'], $p['add_total_delta'], $p['manual_review_count'],
                                $p['baseline_at'] ?? 'ninguno', $p['snapshot_drift_total']
                            ));
                            foreach ($p['plan'] as $r) {
                                $this->line(sprintf(
                                    '    prod %d / var %s | %s | legacy %.3f | location %.3f | drift %+.3f | delta %+.3f | %s%s',
                                    $r['product_id'], $r['product_variant_id'] ?? 'simple', $r['classification'],
                                    $r['legacy'], $r['warehouse_location_quantity'], $r['snapshot_drift'], $r['delta'], $r['action'],
                                    $r['reasons'] ? ' ('.implode(',', $r['reasons']).')' : ''
                                ));
                            }
                            $summary['add_candidates'] += $p['add_count'];
                            $summary['manual_review'] += $p['manual_review_count'];
                            continue;
                        }

                        if ($applyIncremental) {
                            // Plan previo (fuera de la transacción) → expectativas.
                            // applyIncremental revalida DENTRO de la transacción y
                            // aborta si algo cambió: nunca aplica el plan viejo.
                            $pre = $service->planIncremental($warehouseId);
                            $expect = [];
                            foreach ($pre['plan'] as $r) {
                                $k = $r['product_id'].':'.((int) ($r['product_variant_id'] ?: 0));
                                $expect[$k] = [
                                    'action' => $r['action'],
                                    'delta' => $r['delta'],
                                    'legacy' => $r['legacy'],
                                    'location_before' => $r['warehouse_location_quantity'],
                                    'classification' => $r['classification'],
                                ];
                            }
                            $pid = ($productFilter !== null && $productFilter !== '') ? (int) $productFilter : null;
                            $res = $service->applyIncremental($warehouseId, $pid, $expect);
                            $this->line(sprintf(
                                '  Warehouse/CD %d (%s): apply-incremental → APLICADO %d (%.3f) | YA_APLICADO %d | MANUAL_REVIEW %d | baseline %s',
                                $warehouseId, $res['warehouse_name'], $res['applied_count'], $res['applied_total_delta'],
                                $res['skipped_already_applied_count'], $res['manual_review_count'], $res['baseline_at'] ?? 'ninguno'
                            ));
                            foreach ($res['applied'] as $a) {
                                $this->line(sprintf(
                                    '    APLICADO prod %d / var %s | +%.3f | ubicación %.3f → %.3f | mov %d',
                                    $a['product_id'], $a['product_variant_id'] ?? 'simple',
                                    $a['delta'], $a['location_before'], $a['location_after'], $a['movement_id']
                                ));
                            }
                            foreach ($res['skipped_already_applied'] as $a) {
                                $this->line(sprintf(
                                    '    YA_APLICADO prod %d / var %s | mov %d (idempotente, 0 escrituras)',
                                    $a['product_id'], $a['product_variant_id'] ?? 'simple', $a['movement_id']
                                ));
                            }
                            foreach ($res['manual_review'] as $m) {
                                $this->line(sprintf(
                                    '    MANUAL_REVIEW prod %d / var %s | %s%s',
                                    $m['product_id'], $m['product_variant_id'] ?? 'simple', $m['classification'],
                                    $m['reasons'] ? ' ('.implode(',', $m['reasons']).')' : ''
                                ));
                            }
                            $summary['applied'] += $res['applied_count'];
                            $summary['manual_review'] += $res['manual_review_count'];
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
        if ($plan) {
            $this->info(sprintf(
                'Summary: %d tenants, %d warehouses/CD, %d ADD candidates, %d MANUAL_REVIEW, %d failures.',
                $summary['tenants'], $summary['warehouses'],
                $summary['add_candidates'], $summary['manual_review'], $summary['failed']
            ));
        } elseif ($applyIncremental) {
            $this->info(sprintf(
                'Summary: %d tenants, %d warehouses/CD, %d applied, %d MANUAL_REVIEW, %d failures.',
                $summary['tenants'], $summary['warehouses'],
                $summary['applied'], $summary['manual_review'], $summary['failed']
            ));
        } else {
            $this->info(sprintf(
                'Summary: %d tenants, %d warehouses/CD, %d reconciled, %d pending differences, %d failures.',
                $summary['tenants'], $summary['warehouses'],
                $summary['reconciled'], $summary['differences'], $summary['failed']
            ));
        }

        if ($plan) {
            $this->comment('Modo plan: sólo lectura, no se modificó inventario. ADD = seguro sumar el delta con --apply-incremental; MANUAL_REVIEW = requiere revisión.');
        } elseif ($applyIncremental) {
            $this->comment('Modo apply-incremental: sólo se sumaron cantidades legacy no migradas (LEGACY_ONLY_PENDING/ADD), revalidadas en transacción. product_warehouse permanece intacto.');
        } elseif (! $apply) {
            $this->comment('Modo auditoría: no se modificó inventario. Usa --apply (almacén vacío) o --apply-incremental (divergencia puntual) sólo después de revisar el resultado.');
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
