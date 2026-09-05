<?php

namespace App\Console\Commands;

use App\Models\InventoryTransitionState;
use App\Services\InventoryCompatibilityService;
use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProdexInventoryTransition extends Command
{
    protected $signature = 'prodex:inventory-transition
        {--tenants=* : Tenant IDs to inspect or update. Defaults to all tenants.}
        {--warehouse= : Optional warehouse/CD ID inside each selected tenant.}
        {--mode=audit : audit|readiness|legacy_only|shadow_compare|dual_write|location_primary}';

    protected $description = 'Inspect or safely change per-warehouse inventory transition mode.';

    public function handle(): int
    {
        $mode = trim((string) $this->option('mode'));
        $allowed = [
            'audit', 'readiness',
            InventoryTransitionState::MODE_LEGACY_ONLY,
            InventoryTransitionState::MODE_SHADOW_COMPARE,
            InventoryTransitionState::MODE_DUAL_WRITE,
            InventoryTransitionState::MODE_LOCATION_PRIMARY,
        ];
        if (! in_array($mode, $allowed, true)) {
            $this->error('Modo inválido. Usa: audit, readiness, legacy_only, shadow_compare, dual_write o location_primary.');
            return self::FAILURE;
        }

        $tenantIds = array_filter((array) $this->option('tenants'));
        $query = Tenant::query()->orderBy('id');
        if ($tenantIds) $query->whereIn('id', $tenantIds);

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        $warehouseFilter = $this->option('warehouse');
        $failures = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->info("Tenant {$tenant->id}");

            try {
                tenancy()->initialize($tenant);

                foreach (['inventory_locations', 'inventory_location_stocks', 'inventory_location_movements', 'inventory_transition_states'] as $table) {
                    if (! Schema::connection('tenant')->hasTable($table)) {
                        throw new \RuntimeException("Falta {$table}. Ejecuta primero php artisan prodex:tenant-upgrade.");
                    }
                }

                $warehouseIds = \App\Models\Warehouse::whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($warehouseFilter !== null && $warehouseFilter !== '') {
                    $warehouseIds = array_values(array_filter($warehouseIds, fn ($id) => $id === (int) $warehouseFilter));
                }

                $service = app(InventoryCompatibilityService::class);
                foreach ($warehouseIds as $warehouseId) {
                    try {
                        if ($mode === 'readiness') {
                            // READ-ONLY: never mutates the transition mode.
                            $readiness = $service->readinessForLocationPrimary($warehouseId);
                            if ($readiness['ready']) {
                                $this->info("  CD {$warehouseId}: READY para location_primary (ubicación destino {$readiness['inventory_location_id']}).");
                            } else {
                                $failures++;
                                $this->warn("  CD {$warehouseId}: NOT READY — ".implode(' | ', $readiness['reasons']));
                            }
                            continue;
                        }

                        if ($mode === 'audit') {
                            $audit = $service->audit($warehouseId);
                            $state = $service->state($warehouseId);
                        } elseif ($mode === InventoryTransitionState::MODE_LEGACY_ONLY) {
                            $state = $service->returnToLegacyOnly($warehouseId);
                            $audit = $service->audit($warehouseId);
                        } elseif ($mode === InventoryTransitionState::MODE_SHADOW_COMPARE) {
                            $state = $service->enableShadowCompare($warehouseId);
                            $audit = $service->audit($warehouseId);
                        } elseif ($mode === InventoryTransitionState::MODE_DUAL_WRITE) {
                            $state = $service->enableDualWrite($warehouseId);
                            $audit = $service->audit($warehouseId);
                        } else {
                            $state = $service->promoteToLocationPrimary($warehouseId);
                            $audit = $service->audit($warehouseId);
                        }

                        $this->line(sprintf(
                            '  CD %d: mode=%s status=%s legacy=%.3f location=%.3f diferencias=%d',
                            $warehouseId,
                            $state->mode,
                            $state->status,
                            $audit['legacy_total'],
                            $audit['location_total'],
                            count($audit['differences'])
                        ));
                    } catch (Throwable $e) {
                        $failures++;
                        $this->error("  CD {$warehouseId}: ".$e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error('  Failed: '.$e->getMessage());
            } finally {
                if (function_exists('tenancy') && tenancy()->initialized) tenancy()->end();
            }
        }

        if ($mode === 'audit') {
            $this->comment('Modo auditoría: no se cambió la fuente productiva ni se activó dual-write.');
        } elseif ($mode === InventoryTransitionState::MODE_DUAL_WRITE) {
            $this->warn('Dual-write solo sincroniza rutas que sean migradas explícitamente al InventoryCompatibilityService. product_warehouse sigue siendo la fuente productiva.');
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
