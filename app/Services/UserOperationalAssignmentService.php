<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserOperationalAssignmentService
{
    public function effectiveAssignment(User $user, ?Carbon $at = null): array
    {
        $at = $at ?: now();
        $temporary = $this->activeTemporaryAssignment($user, $at);

        if ($temporary) {
            $warehouse = $this->activeWarehouse($temporary->temporary_warehouse_id);
            $branch = $this->activeBranch($temporary->temporary_branch_id)
                ?: $this->activeBranch($warehouse?->branch_id);
            $location = $this->activeInventoryLocation($temporary->temporary_inventory_location_id)
                ?: $this->activeInventoryLocation($branch?->default_inventory_location_id)
                ?: $this->activeInventoryLocation($warehouse?->default_inventory_location_id);
            $drawer = $this->activeDrawerForContext(
                $temporary->temporary_cash_drawer_id,
                $warehouse?->id,
                $branch?->id,
                $location?->id
            );

            return $this->context('temporary', $warehouse, $branch, $location, $drawer, $temporary, $user);
        }

        $warehouse = $this->activeWarehouse($user->default_warehouse_id);
        $branch = $this->activeBranch($user->default_branch_id)
            ?: $this->activeBranch(optional($user->employee)->branch_id)
            ?: $this->activeBranch($warehouse?->branch_id);
        $location = $this->activeInventoryLocation($user->default_inventory_location_id)
            ?: $this->activeInventoryLocation($branch?->default_inventory_location_id)
            ?: $this->activeInventoryLocation($warehouse?->default_inventory_location_id);
        $drawer = $this->activeDrawerForContext(
            $user->default_cash_drawer_id,
            $warehouse?->id,
            $branch?->id,
            $location?->id
        );

        return $this->context('default', $warehouse, $branch, $location, $drawer, null, $user);
    }

    public function activeTemporaryAssignment(User $user, ?Carbon $at = null): ?UserOperationalAssignment
    {
        if (! Schema::hasTable('user_operational_assignments')) {
            return null;
        }

        $at = $at ?: now();

        return UserOperationalAssignment::with([
                'temporaryWarehouse',
                'temporaryBranch',
                'temporaryInventoryLocation',
                'temporaryCashDrawer',
            ])
            ->where('user_id', $user->id)
            ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
            ->where('starts_at', '<=', $at)
            ->where(function ($query) use ($at) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function canOverride(User $user): bool
    {
        return $user->hasPermissionName('cash_register_override_assignment');
    }

    public function allowedWarehouseIds(User $user): array
    {
        if ((int) $user->is_all_warehouses === 1) {
            return Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return UserWarehouse::where('user_id', $user->id)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function allowedBranchIds(User $user): array
    {
        return app(BranchScopeService::class)->allowedBranchIds($user);
    }

    public function allowedInventoryLocationIds(User $user): array
    {
        return app(InventoryLocationScopeService::class)->allowedLocationIds($user);
    }

    /**
     * Legacy validation kept while warehouse-based POS routes are migrated.
     */
    public function validateUserDefaults(User $user, ?int $warehouseId, ?int $cashDrawerId): void
    {
        if (! $warehouseId && ! $cashDrawerId) return;

        if (! $warehouseId) {
            throw ValidationException::withMessages(['default_warehouse_id' => 'Seleccione un warehouse habitual.']);
        }
        if (! in_array((int) $warehouseId, $this->allowedWarehouseIds($user), true)) {
            throw ValidationException::withMessages(['default_warehouse_id' => 'El warehouse habitual debe estar dentro de los warehouses permitidos.']);
        }
        if (! $cashDrawerId) {
            throw ValidationException::withMessages(['default_cash_drawer_id' => 'Seleccione una caja física habitual.']);
        }

        $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
        if (! $drawer || ! $drawer->is_active || (int) $drawer->warehouse_id !== (int) $warehouseId) {
            throw ValidationException::withMessages(['default_cash_drawer_id' => 'La caja física habitual debe estar activa y pertenecer al warehouse habitual.']);
        }
    }

    public function validateUserOperationalDefaults(
        User $user,
        ?int $branchId,
        ?int $locationId,
        ?int $cashDrawerId = null
    ): void {
        if (! $branchId && ! $locationId && ! $cashDrawerId) return;

        if (! $branchId) {
            throw ValidationException::withMessages(['default_branch_id' => 'Seleccione una sucursal habitual.']);
        }
        if (! in_array($branchId, $this->allowedBranchIds($user), true)) {
            throw ValidationException::withMessages(['default_branch_id' => 'La sucursal habitual debe estar dentro del alcance del usuario.']);
        }
        if (! $locationId) {
            throw ValidationException::withMessages(['default_inventory_location_id' => 'Seleccione una ubicación de inventario habitual.']);
        }

        $location = InventoryLocation::active()->find($locationId);
        if (! $location || (int) $location->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages(['default_inventory_location_id' => 'La ubicación predeterminada debe estar activa y pertenecer a la sucursal seleccionada.']);
        }

        if ($cashDrawerId) {
            $drawer = $this->activeDrawerForContext($cashDrawerId, null, $branchId, $locationId);
            if (! $drawer) {
                throw ValidationException::withMessages(['default_cash_drawer_id' => 'La caja física debe pertenecer a la sucursal y ubicación operativa seleccionadas.']);
            }
        }
    }

    /**
     * Legacy request validator retained until POS payloads use branch/location IDs.
     */
    public function validateRequestedAssignment(User $user, ?int $warehouseId, ?int $cashDrawerId, bool $requireDrawer = true): void
    {
        // The historical PosController still calls this method before creating a
        // sale. Once the frontend sends branch_id + inventory_location_id, route
        // that same call through the new operational validator instead of forcing
        // a branch-owned cash drawer to belong to a legacy warehouse.
        if (app()->bound('request') && app(PosLocationStockBridge::class)->isLocationPosRequest(request())) {
            $this->validateRequestedOperationalAssignment(
                $user,
                request()->filled('branch_id') ? (int) request()->input('branch_id') : null,
                request()->filled('inventory_location_id') ? (int) request()->input('inventory_location_id') : null,
                $cashDrawerId,
                $requireDrawer
            );
            return;
        }

        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'Seleccione un warehouse.']);
        }
        if ($requireDrawer && ! $cashDrawerId) {
            throw ValidationException::withMessages(['cash_drawer_id' => 'Seleccione una caja física.']);
        }

        if ($cashDrawerId) {
            $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
            if (! $drawer || ! $drawer->is_active || (int) $drawer->warehouse_id !== (int) $warehouseId) {
                throw ValidationException::withMessages(['cash_drawer_id' => 'La caja física debe estar activa y pertenecer al warehouse seleccionado.']);
            }
        }

        if ($this->canOverride($user)) return;

        $effective = $this->effectiveAssignment($user);
        if ((int) $effective['warehouse_id'] !== (int) $warehouseId || (int) $effective['cash_drawer_id'] !== (int) $cashDrawerId) {
            throw new AuthorizationException('No tiene permiso para operar fuera de su warehouse/caja asignada.');
        }
    }

    public function validateRequestedOperationalAssignment(
        User $user,
        ?int $branchId,
        ?int $locationId,
        ?int $cashDrawerId,
        bool $requireDrawer = true
    ): void {
        if (! $branchId) throw ValidationException::withMessages(['branch_id' => 'Seleccione una sucursal.']);
        if (! $locationId) throw ValidationException::withMessages(['inventory_location_id' => 'Seleccione una ubicación de inventario.']);
        if ($requireDrawer && ! $cashDrawerId) throw ValidationException::withMessages(['cash_drawer_id' => 'Seleccione una caja física.']);

        if (! in_array($branchId, $this->allowedBranchIds($user), true)) {
            throw new AuthorizationException('No tiene acceso a la sucursal seleccionada.');
        }
        if (! in_array($locationId, $this->allowedInventoryLocationIds($user), true)) {
            throw new AuthorizationException('No tiene acceso a la ubicación de inventario seleccionada.');
        }

        $location = $this->activeInventoryLocation($locationId);
        if (! $location || (int) $location->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages(['inventory_location_id' => 'La ubicación de inventario no pertenece a la sucursal seleccionada.']);
        }

        if ($cashDrawerId && ! $this->activeDrawerForContext($cashDrawerId, null, $branchId, $locationId)) {
            throw ValidationException::withMessages(['cash_drawer_id' => 'La caja física no pertenece al contexto operativo seleccionado.']);
        }

        if ($this->canOverride($user)) return;

        $effective = $this->effectiveAssignment($user);
        if ((int) $effective['branch_id'] !== $branchId
            || (int) $effective['inventory_location_id'] !== $locationId
            || (int) $effective['cash_drawer_id'] !== (int) $cashDrawerId) {
            throw new AuthorizationException('No tiene permiso para operar fuera de su sucursal/ubicación/caja asignada.');
        }
    }

    private function context($source, $warehouse, $branch, $location, $drawer, $assignment, User $user): array
    {
        return [
            'source' => $source,
            'warehouse_id' => $warehouse ? (int) $warehouse->id : null,
            'branch_id' => $branch ? (int) $branch->id : null,
            'inventory_location_id' => $location ? (int) $location->id : null,
            'cash_drawer_id' => $drawer ? (int) $drawer->id : null,
            'warehouse' => $warehouse,
            'branch' => $branch,
            'inventory_location' => $location,
            'cash_drawer' => $drawer,
            'assignment' => $assignment,
            'can_override' => $this->canOverride($user),
        ];
    }

    private function activeWarehouse($warehouseId): ?Warehouse
    {
        if (! $warehouseId) return null;
        return Warehouse::whereNull('deleted_at')->find($warehouseId);
    }

    private function activeBranch($branchId): ?Branch
    {
        if (! $branchId) return null;
        return Branch::whereNull('deleted_at')->where('is_active', true)->find($branchId);
    }

    private function activeInventoryLocation($locationId): ?InventoryLocation
    {
        if (! $locationId) return null;
        return InventoryLocation::active()->find($locationId);
    }

    private function activeDrawerForContext($cashDrawerId, $warehouseId, $branchId, $locationId): ?CashDrawer
    {
        if (! $cashDrawerId) return null;

        $query = CashDrawer::whereNull('deleted_at')->where('is_active', true)->whereKey($cashDrawerId);
        $drawer = $query->first();
        if (! $drawer) return null;

        // New branch/location ownership takes precedence when configured.
        if ($drawer->branch_id || $drawer->inventory_location_id) {
            if ($branchId && (int) $drawer->branch_id !== (int) $branchId) return null;
            if ($locationId && $drawer->inventory_location_id && (int) $drawer->inventory_location_id !== (int) $locationId) return null;
            return $drawer;
        }

        // Legacy cash drawers remain valid while their POS routes still use warehouse.
        if ($warehouseId && (int) $drawer->warehouse_id === (int) $warehouseId) return $drawer;

        return null;
    }
}
