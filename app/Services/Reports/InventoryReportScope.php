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
 *
 * GRANULARIDAD DE ALCANCE PARA REPORTES — decisión arquitectónica (auditada)
 * ------------------------------------------------------------------------------
 * Un usuario CON el permiso del reporte analiza a nivel SUCURSAL / ALMACÉN
 * ASIGNADO, NO a nivel InventoryLocation operativa. Evidencia:
 *   · `ReportController::Internal_Location_Report` (reporte moderno de ubicación)
 *     acota por `is_all_warehouses` + `UserWarehouse`, no por location.
 *   · `SalesReportingScopeService` (alcance de LECTURA canónico de ventas) usa
 *     `BranchScopeService::allowedBranchIds` + `UserOperationalAssignmentService::allowedWarehouseIds`;
 *     no restringe por InventoryLocation.
 *   · `InventoryLocationScopeService::allowedLocationIds` se usa SÓLO en
 *     contextos OPERATIVOS (POS: desde dónde vender; recepción de traslados),
 *     nunca en reportes.
 * Por tanto los reportes usan `BranchScopeService` / `WarehouseScopeService` y
 * el usuario puede ver TODAS las InventoryLocation de sus sucursales permitidas.
 * El filtro "Ubicación" del reporte es una CONVENIENCIA de análisis, acotada
 * SIEMPRE al alcance de sucursal permitido (no puede ampliarlo).
 *
 * `unscoped` (query sin filtrar) SÓLO para Owner (`role_id === 1`) sin selector —
 * igual que `SalesReportingScopeService`. Un NO-Owner con `is_all_warehouses = 1`
 * pero `user_branches` explícitas NUNCA hace bypass total; sus sucursales las
 * resuelve `BranchScopeService` (que ya prioriza `user_branches`).
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

    /** true = Owner sin selector → sin filtrar (tenant completo, incl. inactivo). */
    private bool $unscoped = false;

    /** true = hay un selector EXPLÍCITO de InventoryLocation (sólo esa ubicación). */
    private bool $locationScoped = false;

    private ?int $selectedLocationId = null;

    /** Mensaje de validación (filtro contradictorio) → el controlador responde 422. */
    private ?string $error = null;

    private array $branchNameById;

    private array $warehouseBranchById;

    private array $warehouseNameById;

    private array $locationById; // id => {name, branch_id, warehouse_id}

    public function __construct(User $user, ?int $branchId = null, ?int $locationId = null, ?int $warehouseId = null)
    {
        $branchScope = app(BranchScopeService::class);
        $warehouseScope = app(WarehouseScopeService::class);

        $allowedBranches = $branchScope->allowedBranchIds($user);
        $allowedWarehouses = $warehouseScope->allowedWarehouseIds($user);
        $isOwner = (int) $user->role_id === 1;
        $hasSelector = (bool) $branchId || (bool) $locationId || (bool) $warehouseId;
        $locTable = Schema::hasTable('inventory_locations');

        $allWarehouseIds = DB::table('warehouses')->whereNull('deleted_at')->pluck('id')->map('intval')->all();
        $allLocationIds = $locTable
            ? DB::table('inventory_locations')->whereNull('deleted_at')->pluck('id')->map('intval')->all()
            : [];

        // --- Owner SIN selector → SIN filtrar (mismo criterio que
        //     SalesReportingScopeService::apply para role_id === 1). Incluye
        //     sucursales inactivas y almacenes sin sucursal. Un NO-Owner NUNCA
        //     entra aquí por `is_all_warehouses`.
        if (! $hasSelector && $isOwner) {
            $this->unscoped = true;
            $this->branchIds = $this->allBranchIds();
            $this->legacyWarehouseIds = $allWarehouseIds;
            $this->locationIds = $allLocationIds;
        } elseif ($locationId) {
            // Selector MODERNO: una InventoryLocation concreta. TODOS los
            // movimientos y el stock se acotan a ESA `inventory_location_id`
            // (ventas/dev. de venta incluidas, aunque tengan `branch_id`). Los
            // registros legacy sin `inventory_location_id` no se pueden atribuir
            // a una ubicación concreta → fuera de la vista por ubicación.
            $loc = $locTable ? DB::table('inventory_locations')->whereNull('deleted_at')->find($locationId) : null;
            if (! $loc) {
                $this->denied = true;
                $this->branchIds = [];
            } elseif ($branchId && (int) $loc->branch_id !== $branchId) {
                // Filtro contradictorio: la ubicación no pertenece a la sucursal.
                $this->error = 'La ubicación seleccionada no pertenece a la sucursal indicada.';
                $this->denied = true;
                $this->branchIds = [(int) $loc->branch_id];
            } elseif (! $isOwner && ! in_array((int) $loc->branch_id, $allowedBranches, true)) {
                $this->denied = true;
                $this->branchIds = [(int) $loc->branch_id];
            } else {
                $this->branchIds = [(int) $loc->branch_id];
                $this->locationScoped = true;
                $this->selectedLocationId = $locationId;
            }
            $this->legacyWarehouseIds = [];
            $this->locationIds = [$locationId];
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
            // Selector LEGACY (compatibilidad). Sólo el `warehouse_id` exacto y
            // las InventoryLocation que SON ese almacén (`warehouse_id = X`). NO
            // se añaden ubicaciones sueltas de la misma sucursal.
            $wBranch = (int) (DB::table('warehouses')->where('id', $warehouseId)->value('branch_id') ?: 0);
            if (! $isOwner && ! $warehouseScope->canAccess($user, $warehouseId)) {
                $this->denied = true;
            }
            $this->branchIds = $wBranch ? [$wBranch] : [];
            $this->legacyWarehouseIds = [$warehouseId];
            $this->locationIds = $locTable
                ? DB::table('inventory_locations')->whereNull('deleted_at')->where('warehouse_id', $warehouseId)
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

    /** true = el usuario pidió explícitamente UNA InventoryLocation. */
    public function isLocationScoped(): bool
    {
        return $this->locationScoped;
    }

    public function selectedLocationId(): ?int
    {
        return $this->selectedLocationId;
    }

    /** Mensaje de filtro contradictorio (sucursal ≠ sucursal de la ubicación) → 422. */
    public function error(): ?string
    {
        return $this->error;
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

    /**
     * Ubicaciones a leer DIRECTAMENTE de `inventory_location_stocks` para la
     * existencia actual.
     *
     *  · SELECTOR EXPLÍCITO de ubicación → SÓLO esa `inventory_location_id`,
     *    tenga o no `warehouse_id`. El usuario pidió físicamente ESA ubicación:
     *    se lee su stock real, NO el `product_warehouse` del almacén asociado y
     *    NO {@see InventoryReadService} (que resolvería por almacén).
     *  · Alcance general / sucursal → las ubicaciones SIN almacén (idéntico a
     *    {@see stockLocationIds()}); los almacenes van por `stockWarehouseIds()`
     *    + {@see InventoryReadService}, sin doble conteo.
     *
     * @return int[]
     */
    public function stockLocationIdsForDirectRead(): array
    {
        if ($this->locationScoped && $this->selectedLocationId !== null) {
            return [$this->selectedLocationId];
        }

        return $this->stockLocationIds();
    }

    public function hasModernLocations(): bool
    {
        return ! empty($this->locationIds);
    }

    /**
     * Fuente con `branch_id` (sales, sale_returns):
     *   (alias.branch_id IN branchIds)
     *   OR (alias.branch_id IS NULL AND alias.warehouse_id IN legacyWarehouseIds)
     *
     * SELECTOR EXPLÍCITO de ubicación → SÓLO `inventory_location_id = seleccionada`.
     * Una venta moderna de OTRA ubicación de la misma sucursal NO entra, y los
     * registros legacy (sin `inventory_location_id`) tampoco — no se pueden
     * atribuir a una ubicación concreta.
     */
    public function applyBranchScope($query, string $alias)
    {
        if ($this->denied) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->unscoped) {
            return $query; // visión total del tenant → sin filtrar
        }
        if ($this->locationScoped) {
            return $query->whereIn("{$alias}.inventory_location_id", $this->locationIds ?: [0]);
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
