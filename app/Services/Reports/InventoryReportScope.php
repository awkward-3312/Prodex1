<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\BranchScopeService;
use App\Services\WarehouseScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance branch-first / legacy-fallback COMPARTIDO por los reportes de
 * inventario avanzados (Kardex valorizado, Rotación).
 *
 * Regla — la MISMA que ya usa PRODEX en {@see \App\Services\SalesReportingScopeService}:
 *
 *   MODERNO   documento con `branch_id` (ventas / devoluciones de venta) o
 *             `inventory_location_id` (compras / dev. compra / ajustes / daños /
 *             traslados) → se filtra por la sucursal / ubicación real.
 *   LEGACY    `branch_id IS NULL` (o `inventory_location_id IS NULL`) →
 *             `warehouse_id` como único fallback.
 *
 * `warehouse_id` NUNCA es la fuente primaria de un registro moderno. Un almacén
 * legacy se traduce a su sucursal (`warehouses.branch_id`) para no perder las
 * ventas POS modernas de esa sucursal (`branch_id != NULL`, `warehouse_id NULL`).
 *
 * El acceso (qué sucursales/almacenes puede ver el usuario) se delega en los
 * servicios de alcance canónicos; aquí NO se reimplementa esa política.
 */
class InventoryReportScope
{
    /** @var int[] Sucursales efectivas del alcance (seleccionada, o todas las permitidas). */
    private array $branchIds;

    /** @var int[] Almacenes legacy de esas sucursales (∩ permitidos del usuario). */
    private array $legacyWarehouseIds;

    /** @var int[] InventoryLocation de esas sucursales (vacío si el tenant no tiene el motor). */
    private array $locationIds;

    private bool $denied = false;

    /** true = usuario con visión total del tenant y sin selector → sin filtrar. */
    private bool $unscoped = false;

    private array $branchNameById;

    private array $warehouseBranchById;

    private array $warehouseNameById;

    private array $locationById; // id => {name, branch_id, warehouse_id}

    public function __construct(User $user, ?int $branchId = null, ?int $warehouseId = null)
    {
        $branchScope = app(BranchScopeService::class);
        $warehouseScope = app(WarehouseScopeService::class);

        $allowedBranches = $branchScope->allowedBranchIds($user);
        $allowedWarehouses = $warehouseScope->allowedWarehouseIds($user);
        $isOwner = (int) $user->role_id === 1;
        $seesWholeTenant = $isOwner || (int) $user->is_all_warehouses === 1;
        $hasSelector = (bool) $branchId || (bool) $warehouseId;
        $locTable = Schema::hasTable('inventory_locations');

        $allWarehouseIds = DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->map('intval')->all();
        $allLocationIds = $locTable
            ? DB::table('inventory_locations')->whereNull('deleted_at')->pluck('id')->map('intval')->all()
            : [];

        // --- SIN selector + visión total del tenant → SIN filtrar ---------------
        // Mismo criterio que SalesReportingScopeService::apply: no ocultar
        // movimientos/stock por `warehouse_id`/`branch_id` NULL cuando el usuario
        // puede ver todo. Incluye sucursales inactivas y almacenes sin sucursal.
        if (! $hasSelector && $seesWholeTenant) {
            $this->unscoped = true;
            $this->branchIds = $this->allBranchIds();
            $this->legacyWarehouseIds = $allWarehouseIds;
            $this->locationIds = $allLocationIds;
        } elseif ($branchId) {
            if (! $isOwner && ! in_array($branchId, $allowedBranches, true)) {
                $this->denied = true;
            }
            $this->branchIds = [$branchId];
            $branchWh = DB::table('warehouses')->whereNull('deleted_at')->where('branch_id', $branchId)
                ->pluck('id')->map('intval')->all();
            $this->legacyWarehouseIds = $isOwner ? $branchWh : array_values(array_intersect($branchWh, $allowedWarehouses));
            $this->locationIds = $locTable
                ? DB::table('inventory_locations')->whereNull('deleted_at')->where('branch_id', $branchId)
                    ->pluck('id')->map('intval')->all()
                : [];
        } elseif ($warehouseId) {
            $wBranch = (int) (DB::table('warehouses')->where('id', $warehouseId)->value('branch_id') ?: 0);
            if (! $isOwner && ! $warehouseScope->canAccess($user, $warehouseId)) {
                $this->denied = true;
            }
            $this->branchIds = $wBranch ? [$wBranch] : [];
            $this->legacyWarehouseIds = [$warehouseId];
            $this->locationIds = $locTable
                ? DB::table('inventory_locations')->whereNull('deleted_at')
                    ->where(function ($q) use ($warehouseId, $wBranch) {
                        $q->where('warehouse_id', $warehouseId);
                        if ($wBranch) {
                            $q->orWhere(fn ($qq) => $qq->where('branch_id', $wBranch)->whereNull('warehouse_id'));
                        }
                    })
                    ->pluck('id')->map('intval')->all()
                : [];
        } else {
            // Usuario NO privilegiado, sin selector → su alcance asignado
            // (almacenes DIRECTAMENTE asignados, no "almacenes de sus sucursales").
            $this->branchIds = $allowedBranches;
            $this->legacyWarehouseIds = $allowedWarehouses;
            $this->locationIds = ($locTable && $allowedBranches)
                ? DB::table('inventory_locations')->whereNull('deleted_at')->whereIn('branch_id', $allowedBranches)
                    ->pluck('id')->map('intval')->all()
                : [];
        }

        $this->legacyWarehouseIds = array_values(array_unique(array_map('intval', $this->legacyWarehouseIds)));
        $this->locationIds = array_values(array_unique(array_map('intval', $this->locationIds)));

        // --- Diccionarios de presentación ---
        $this->branchNameById = DB::table('branches')->pluck('name', 'id')->all();
        $whRows = DB::table('warehouses')->get(['id', 'name', 'branch_id']);
        $this->warehouseNameById = $whRows->pluck('name', 'id')->all();
        $this->warehouseBranchById = $whRows->pluck('branch_id', 'id')->all();
        $this->locationById = [];
        if (Schema::hasTable('inventory_locations')) {
            foreach (DB::table('inventory_locations')->get(['id', 'name', 'branch_id', 'warehouse_id']) as $l) {
                $this->locationById[(int) $l->id] = $l;
            }
        }
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /** @return int[] */
    public function branchIds(): array
    {
        return $this->branchIds;
    }

    /** @return int[] */
    public function legacyWarehouseIds(): array
    {
        return $this->legacyWarehouseIds;
    }

    /** @return int[] */
    public function locationIds(): array
    {
        return $this->locationIds;
    }

    /**
     * Almacenes para {@see \App\Services\InventoryReadService} (existencia actual):
     * los almacenes legacy de las sucursales del alcance. `InventoryReadService`
     * ya resuelve por cada uno legacy `product_warehouse` o moderno
     * `inventory_location_stocks` según `inventory_transition_states` — sin doble
     * conteo.
     *
     * @return int[]
     */
    public function stockWarehouseIds(): array
    {
        return $this->legacyWarehouseIds;
    }

    /**
     * Ubicaciones del alcance SIN almacén (`warehouse_id IS NULL`): su stock vive
     * sólo en `inventory_location_stocks` y {@see InventoryReadService} (warehouse-
     * keyed) no lo alcanza. El servicio suma este stock aparte para reconciliar
     * — sin doble conteo, porque estas ubicaciones no mapean a ningún almacén.
     *
     * @return int[]
     */
    public function stockLocationIds(): array
    {
        $out = [];
        foreach ($this->locationIds as $id) {
            $l = $this->locationById[$id] ?? null;
            if ($l && ($l->warehouse_id === null || (int) $l->warehouse_id === 0)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    public function hasModernLocations(): bool
    {
        return ! empty($this->locationIds);
    }

    /**
     * Fuente con `branch_id` (sales, sale_returns):
     *   (alias.branch_id IN branchIds)
     *   OR (alias.branch_id IS NULL AND alias.warehouse_id IN legacyWarehouseIds)
     */
    public function applyBranchScope($query, string $alias)
    {
        if ($this->denied) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->unscoped) {
            return $query; // visión total del tenant → sin filtrar
        }
        $branchIds = $this->branchIds;
        $legacy = $this->legacyWarehouseIds;

        return $query->where(function ($q) use ($alias, $branchIds, $legacy) {
            if ($branchIds) {
                $q->whereIn("{$alias}.branch_id", $branchIds);
            }
            if ($legacy) {
                $q->orWhere(function ($lg) use ($alias, $legacy) {
                    $lg->whereNull("{$alias}.branch_id")
                        ->whereIn("{$alias}.warehouse_id", $legacy);
                });
            }
            if (! $branchIds && ! $legacy) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Fuente con `inventory_location_id` (purchases, purchase_returns,
     * adjustments, damages):
     *   (alias.locCol IN locationIds)
     *   OR (alias.locCol IS NULL AND alias.whCol IN legacyWarehouseIds)
     */
    public function applyLocationScope($query, string $alias, string $locCol = 'inventory_location_id', string $whCol = 'warehouse_id')
    {
        if ($this->denied) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->unscoped) {
            return $query;
        }
        $locations = $this->locationIds;
        $legacy = $this->legacyWarehouseIds;

        return $query->where(function ($q) use ($alias, $locations, $legacy, $locCol, $whCol) {
            if ($locations) {
                $q->whereIn("{$alias}.{$locCol}", $locations);
            }
            if ($legacy) {
                $q->orWhere(function ($lg) use ($alias, $legacy, $locCol, $whCol) {
                    $lg->whereNull("{$alias}.{$locCol}")
                        ->whereIn("{$alias}.{$whCol}", $legacy);
                });
            }
            if (! $locations && ! $legacy) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** ¿La fila (por sus columnas modernas/legacy) cae dentro del alcance? Para transfers, evaluado por pierna. */
    public function rowInScope(?int $locationId, ?int $warehouseId): bool
    {
        if ($this->denied) {
            return false;
        }
        if ($this->unscoped) {
            return $locationId !== null || $warehouseId !== null;
        }
        if ($locationId) {
            return in_array($locationId, $this->locationIds, true);
        }
        if ($warehouseId) {
            return in_array($warehouseId, $this->legacyWarehouseIds, true);
        }

        return false;
    }

    /** Sucursal de una fila. Prioridad: branch_id directo → ubicación → almacén. */
    public function resolveBranchName(?int $branchId, ?int $locationId, ?int $warehouseId): ?string
    {
        $bid = $branchId
            ?: ($locationId && isset($this->locationById[$locationId]) ? (int) $this->locationById[$locationId]->branch_id : null)
            ?: ($warehouseId ? (int) ($this->warehouseBranchById[$warehouseId] ?? 0) : null);

        return $bid ? ($this->branchNameById[$bid] ?? null) : null;
    }

    /**
     * Ubicación de una fila para presentar en el Kardex.
     *  - moderna: nombre real de la InventoryLocation.
     *  - legacy:  nombre del almacén, marcado como fallback.
     *
     * @return array{name: ?string, basis: string}  basis = 'modern' | 'legacy' | 'none'
     */
    public function resolveLocation(?int $locationId, ?int $warehouseId): array
    {
        if ($locationId && isset($this->locationById[$locationId])) {
            return ['name' => $this->locationById[$locationId]->name, 'basis' => 'modern'];
        }
        if ($warehouseId && isset($this->warehouseNameById[$warehouseId])) {
            return ['name' => $this->warehouseNameById[$warehouseId], 'basis' => 'legacy'];
        }

        return ['name' => null, 'basis' => 'none'];
    }

    /** TODAS las sucursales del tenant (incl. inactivas): la visión total no oculta historia. */
    private function allBranchIds(): array
    {
        return DB::table('branches')->whereNull('deleted_at')->pluck('id')->map('intval')->all();
    }
}
