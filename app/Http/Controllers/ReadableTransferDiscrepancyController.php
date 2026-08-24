<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read access to transfer discrepancies is broader than resolution access.
 *
 * Inventory/transfer viewers need to see missing merchandise without being able
 * to resolve an incident. Resolution remains protected by transfer_issue_manage
 * in the inherited resolve() implementation.
 */
class ReadableTransferDiscrepancyController extends FinalTransferDiscrepancyController
{
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $canReceive = $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION);
        $canManage = $user->hasPermissionName(self::MANAGE_PERMISSION);
        $canView = (int) $user->role_id === 1
            || $canReceive
            || $canManage
            || $user->hasPermissionName('transfer_view')
            || $user->hasPermissionName('damage_view')
            || $user->hasPermissionName('products_view');

        abort_unless($canView, 403);

        // Existing operational users keep the full battle-tested query and all
        // resolution metadata from the final logistics controller.
        if ($canReceive || $canManage) {
            return parent::index($request);
        }

        if (! Schema::hasTable('transfer_discrepancies')) {
            return response()->json([
                'issues' => [],
                'open_count' => 0,
                'can_manage' => false,
                'resolutions' => [],
            ]);
        }

        $hasLocations = Schema::hasTable('inventory_locations')
            && Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

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

        if ($hasLocations) {
            $query->leftJoin('inventory_locations as fil', 'fil.id', '=', 't.from_inventory_location_id')
                ->leftJoin('inventory_locations as til', 'til.id', '=', 't.to_inventory_location_id')
                ->leftJoin('branches as fb', 'fb.id', '=', 'fil.branch_id')
                ->leftJoin('branches as tb', 'tb.id', '=', 'til.branch_id');
        }

        // Owner sees company-wide discrepancies. Other read-only users see only
        // transfers touching their physical inventory scope.
        if ((int) $user->role_id !== 1) {
            $locationIds = $hasLocations
                ? app(InventoryLocationScopeService::class)->receivingLocationIds($user)
                : [];
            $warehouseIds = app(TransferLogisticsService::class)->warehouseIdsForUser($user);

            if (! $locationIds && ! $warehouseIds) {
                return response()->json([
                    'issues' => [],
                    'open_count' => 0,
                    'can_manage' => false,
                    'resolutions' => [],
                ]);
            }

            $query->where(function ($scope) use ($hasLocations, $locationIds, $warehouseIds) {
                if ($hasLocations && $locationIds) {
                    $scope->whereIn('t.to_inventory_location_id', $locationIds)
                        ->orWhereIn('t.from_inventory_location_id', $locationIds);
                }
                if ($warehouseIds) {
                    $method = ($hasLocations && $locationIds) ? 'orWhere' : 'where';
                    $scope->{$method}(function ($legacy) use ($warehouseIds, $hasLocations) {
                        if ($hasLocations) {
                            $legacy->whereNull('t.from_inventory_location_id')
                                ->whereNull('t.to_inventory_location_id');
                        }
                        $legacy->where(function ($warehouses) use ($warehouseIds) {
                            $warehouses->whereIn('t.to_warehouse_id', $warehouseIds)
                                ->orWhereIn('t.from_warehouse_id', $warehouseIds);
                        });
                    });
                }
            });
        }

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

        if ($hasLocations) {
            $select[] = 't.from_inventory_location_id';
            $select[] = 't.to_inventory_location_id';
            $select[] = DB::raw("CASE WHEN fil.branch_id IS NOT NULL THEN CONCAT(COALESCE(fb.name, 'Sucursal'), ' · ', fil.name) ELSE COALESCE(fw.name, fil.name, 'Centro de Distribución') END as from_warehouse");
            $select[] = DB::raw("CASE WHEN til.branch_id IS NOT NULL THEN CONCAT(COALESCE(tb.name, 'Sucursal'), ' · ', til.name) ELSE COALESCE(tw.name, til.name, 'Centro de Distribución') END as to_warehouse");
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
            'can_manage' => false,
            'resolutions' => [],
        ]);
    }
}
