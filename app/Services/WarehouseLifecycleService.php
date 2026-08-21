<?php

namespace App\Services;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseLifecycleService
{
    public function assertCanArchive(int $warehouseId): void
    {
        $warehouse = Warehouse::whereNull('deleted_at')->findOrFail($warehouseId);

        if (Warehouse::whereNull('deleted_at')->where('id', '!=', $warehouse->id)->count() < 1) {
            throw ValidationException::withMessages([
                'warehouse' => 'PRODEX requiere al menos un almacén/centro de distribución activo. Crea otro antes de desactivar este.',
            ]);
        }

        if ($this->hasLegacyStock($warehouseId)) {
            throw ValidationException::withMessages([
                'warehouse' => 'No se puede desactivar este almacén/CD porque todavía contiene existencias. Transfiere o ajusta el inventario primero.',
            ]);
        }

        if ($this->hasLocationStock($warehouseId)) {
            throw ValidationException::withMessages([
                'warehouse' => 'No se puede desactivar este almacén/CD porque sus ubicaciones todavía contienen existencias o reservas.',
            ]);
        }

        if ($this->hasOpenTransfers($warehouseId)) {
            throw ValidationException::withMessages([
                'warehouse' => 'No se puede desactivar este almacén/CD mientras tenga transferencias pendientes, aprobadas o en tránsito.',
            ]);
        }

        if ($this->hasActiveLegacyDrawers($warehouseId)) {
            throw ValidationException::withMessages([
                'warehouse' => 'Hay cajas físicas activas que todavía utilizan este almacén como contexto legado. Migra o desactiva esas cajas primero.',
            ]);
        }
    }

    private function hasLegacyStock(int $warehouseId): bool
    {
        if (! Schema::hasTable('product_warehouse')) return false;

        return DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->whereRaw('ABS(COALESCE(qte, 0)) > 0.0005')
            ->exists();
    }

    private function hasLocationStock(int $warehouseId): bool
    {
        if (! Schema::hasTable('inventory_locations') || ! Schema::hasTable('inventory_location_stocks')) {
            return false;
        }

        return DB::table('inventory_location_stocks as stock')
            ->join('inventory_locations as location', 'location.id', '=', 'stock.inventory_location_id')
            ->where('location.warehouse_id', $warehouseId)
            ->whereNull('location.deleted_at')
            ->where(function ($query) {
                $query->whereRaw('ABS(COALESCE(stock.quantity, 0)) > 0.0005')
                    ->orWhereRaw('ABS(COALESCE(stock.reserved_quantity, 0)) > 0.0005');
            })
            ->exists();
    }

    private function hasOpenTransfers(int $warehouseId): bool
    {
        if (! Schema::hasTable('transfers')) return false;

        $query = DB::table('transfers')
            ->whereNull('deleted_at')
            ->where(function ($scope) use ($warehouseId) {
                $scope->where('from_warehouse_id', $warehouseId)
                    ->orWhere('to_warehouse_id', $warehouseId);
            });

        if (Schema::hasColumn('transfers', 'logistics_status')) {
            return $query->whereNotIn('logistics_status', [
                'received', 'received_with_issues', 'cancelled', 'canceled',
            ])->exists();
        }

        if (Schema::hasColumn('transfers', 'statut')) {
            return $query->whereNotIn('statut', ['completed', 'cancelled', 'canceled'])->exists();
        }

        return $query->exists();
    }

    private function hasActiveLegacyDrawers(int $warehouseId): bool
    {
        if (! Schema::hasTable('cash_drawers') || ! Schema::hasColumn('cash_drawers', 'warehouse_id')) {
            return false;
        }

        $query = DB::table('cash_drawers')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->where('is_active', true);

        // A drawer already migrated to branch/location keeps warehouse_id only as
        // historical compatibility pointer and should not block the CD forever.
        if (Schema::hasColumn('cash_drawers', 'branch_id') && Schema::hasColumn('cash_drawers', 'inventory_location_id')) {
            $query->where(function ($scope) {
                $scope->whereNull('branch_id')->orWhereNull('inventory_location_id');
            });
        }

        return $query->exists();
    }
}
