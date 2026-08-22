<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinalTransferDiscrepancyController extends LocationAwareTransferDiscrepancyController
{
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $canReceive = $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION);
        $canManage = $user->hasPermissionName(self::MANAGE_PERMISSION);
        abort_unless($canReceive || $canManage, 403);

        $warehouseIds = app(TransferLogisticsService::class)->warehouseIdsForUser($user);
        $locationIds = Schema::hasTable('inventory_locations')
            ? app(InventoryLocationScopeService::class)->allowedLocationIds($user)
            : [];

        if (! $warehouseIds && ! $locationIds) {
            return response()->json(['issues' => [], 'open_count' => 0, 'can_manage' => $canManage]);
        }

        $query = DB::table('transfer_discrepancies as d')
            ->join('transfers as t', 't.id', '=', 'd.transfer_id')
            ->join('transfer_details as td', 'td.id', '=', 'd.transfer_detail_id')
            ->join('products as p', 'p.id', '=', 'td.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'td.product_variant_id')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'd.reported_by_user_id')
            ->leftJoin('users as resolver', 'resolver.id', '=', 'd.resolved_by_user_id')
            ->whereNull('t.deleted_at');

        $hasLocationColumns = Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

        if ($hasLocationColumns && Schema::hasTable('inventory_locations')) {
            $query->leftJoin('inventory_locations as fil', 'fil.id', '=', 't.from_inventory_location_id')
                ->leftJoin('inventory_locations as til', 'til.id', '=', 't.to_inventory_location_id');
        }

        $query->where(function ($scope) use ($canManage, $warehouseIds, $locationIds, $hasLocationColumns) {
            if ($hasLocationColumns && $locationIds) {
                if ($canManage) {
                    $scope->whereIn('t.to_inventory_location_id', $locationIds)
                        ->orWhereIn('t.from_inventory_location_id', $locationIds);
                } else {
                    $scope->whereIn('t.to_inventory_location_id', $locationIds);
                }
            }

            if ($warehouseIds) {
                $scope->orWhere(function ($legacy) use ($canManage, $warehouseIds, $hasLocationColumns) {
                    if ($hasLocationColumns) {
                        $legacy->whereNull('t.from_inventory_location_id')
                            ->whereNull('t.to_inventory_location_id');
                    }

                    $legacy->where(function ($warehouseScope) use ($canManage, $warehouseIds) {
                        $warehouseScope->whereIn('t.to_warehouse_id', $warehouseIds);
                        if ($canManage) $warehouseScope->orWhereIn('t.from_warehouse_id', $warehouseIds);
                    });
                });
            }
        });

        if ($request->filled('status')) {
            $query->where('d.resolution_status', $request->string('status')->toString());
        }

        $select = [
            'd.id', 'd.transfer_id', 'd.transfer_detail_id', 'd.warehouse_id',
            'd.type', 'd.quantity', 'd.resolution_status', 'd.resolution_code',
            'd.resolution_reference', 'd.resolution_notes', 'd.notes',
            'd.reported_at', 'd.resolved_at',
            't.Ref as reference', 't.from_warehouse_id', 't.to_warehouse_id',
            'p.name as product_name', 'p.code as product_code', 'pv.name as variant_name',
            DB::raw("TRIM(CONCAT(COALESCE(reporter.firstname, ''), ' ', COALESCE(reporter.lastname, ''))) as reported_by"),
            DB::raw("TRIM(CONCAT(COALESCE(resolver.firstname, ''), ' ', COALESCE(resolver.lastname, ''))) as resolved_by"),
        ];

        if ($hasLocationColumns && Schema::hasTable('inventory_locations')) {
            $select[] = 't.from_inventory_location_id';
            $select[] = 't.to_inventory_location_id';
            $select[] = DB::raw('COALESCE(fil.name, fw.name) as from_warehouse');
            $select[] = DB::raw('COALESCE(til.name, tw.name) as to_warehouse');
        } else {
            $select[] = 'fw.name as from_warehouse';
            $select[] = 'tw.name as to_warehouse';
        }

        $issues = $query
            ->orderByRaw("CASE WHEN d.resolution_status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('d.reported_at')
            ->limit(250)
            ->get($select);

        return response()->json([
            'issues' => $issues,
            'open_count' => $issues->where('resolution_status', 'open')->count(),
            'can_manage' => $canManage,
            'resolutions' => [
                'missing' => [
                    ['value' => 'received_later', 'label' => 'Recibido posteriormente'],
                    ['value' => 'confirmed_loss', 'label' => 'Pérdida confirmada'],
                    ['value' => 'reconciled_by_adjustment', 'label' => 'Conciliado mediante ajuste de inventario'],
                ],
                'defective' => [
                    ['value' => 'released_to_stock', 'label' => 'Liberado a inventario vendible'],
                    ['value' => 'written_off', 'label' => 'Dado de baja'],
                    ['value' => 'returned_to_origin', 'label' => 'Devuelto a bodega origen'],
                    ['value' => 'reconciled_by_adjustment', 'label' => 'Conciliado mediante ajuste de inventario'],
                ],
            ],
        ]);
    }
}
