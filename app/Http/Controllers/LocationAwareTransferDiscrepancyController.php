<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferIssueLocationResolutionService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationAwareTransferDiscrepancyController extends TransferDiscrepancyController
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
        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);

        if (! $warehouseIds && ! $locationIds) {
            return response()->json([
                'issues' => [],
                'open_count' => 0,
                'can_manage' => $canManage,
                'resolutions' => $this->resolutionOptions(),
            ]);
        }

        $query = DB::table('transfer_discrepancies as d')
            ->join('transfers as t', 't.id', '=', 'd.transfer_id')
            ->join('transfer_details as td', 'td.id', '=', 'd.transfer_detail_id')
            ->join('products as p', 'p.id', '=', 'td.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'td.product_variant_id')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 't.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 't.to_warehouse_id')
            ->leftJoin('inventory_locations as fil', 'fil.id', '=', 't.from_inventory_location_id')
            ->leftJoin('inventory_locations as til', 'til.id', '=', 't.to_inventory_location_id')
            ->leftJoin('users as reporter', 'reporter.id', '=', 'd.reported_by_user_id')
            ->leftJoin('users as resolver', 'resolver.id', '=', 'd.resolved_by_user_id')
            ->whereNull('t.deleted_at');

        // Modern transfers are authorized exclusively through physical location
        // scope. Legacy transfers (without location IDs) keep the historical
        // warehouse authorization so existing tenants remain fully compatible.
        $query->where(function ($scope) use ($canManage, $warehouseIds, $locationIds) {
            if ($locationIds) {
                $scope->where(function ($modern) use ($canManage, $locationIds) {
                    $modern->where(function ($hasPhysicalScope) {
                        $hasPhysicalScope->whereNotNull('t.from_inventory_location_id')
                            ->orWhereNotNull('t.to_inventory_location_id');
                    });

                    if ($canManage) {
                        $modern->where(function ($allowed) use ($locationIds) {
                            $allowed->whereIn('t.to_inventory_location_id', $locationIds)
                                ->orWhereIn('t.from_inventory_location_id', $locationIds);
                        });
                    } else {
                        // Receivers may inspect only issues at their destination.
                        $modern->whereIn('t.to_inventory_location_id', $locationIds);
                    }
                });
            }

            if ($warehouseIds) {
                $legacyMethod = $locationIds ? 'orWhere' : 'where';
                $scope->{$legacyMethod}(function ($legacy) use ($canManage, $warehouseIds) {
                    $legacy->whereNull('t.from_inventory_location_id')
                        ->whereNull('t.to_inventory_location_id');

                    if ($canManage) {
                        $legacy->where(function ($allowed) use ($warehouseIds) {
                            $allowed->whereIn('t.to_warehouse_id', $warehouseIds)
                                ->orWhereIn('t.from_warehouse_id', $warehouseIds);
                        });
                    } else {
                        $legacy->whereIn('t.to_warehouse_id', $warehouseIds);
                    }
                });
            }
        });

        if ($request->filled('status')) {
            $query->where('d.resolution_status', $request->string('status')->toString());
        }

        $issues = $query
            ->orderByRaw("CASE WHEN d.resolution_status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('d.reported_at')
            ->limit(250)
            ->get([
                'd.id', 'd.transfer_id', 'd.transfer_detail_id', 'd.warehouse_id',
                'd.type', 'd.quantity', 'd.resolution_status', 'd.resolution_code',
                'd.resolution_reference', 'd.resolution_notes', 'd.notes',
                'd.reported_at', 'd.resolved_at',
                't.Ref as reference', 't.from_warehouse_id', 't.to_warehouse_id',
                't.from_inventory_location_id', 't.to_inventory_location_id',
                DB::raw('COALESCE(fil.name, fw.name) as from_warehouse'),
                DB::raw('COALESCE(til.name, tw.name) as to_warehouse'),
                'fil.name as from_inventory_location', 'til.name as to_inventory_location',
                'fw.name as legacy_from_warehouse', 'tw.name as legacy_to_warehouse',
                'p.name as product_name', 'p.code as product_code', 'pv.name as variant_name',
                DB::raw("TRIM(CONCAT(COALESCE(reporter.firstname, ''), ' ', COALESCE(reporter.lastname, ''))) as reported_by"),
                DB::raw("TRIM(CONCAT(COALESCE(resolver.firstname, ''), ' ', COALESCE(resolver.lastname, ''))) as resolved_by"),
            ]);

        return response()->json([
            'issues' => $issues,
            'open_count' => $issues->where('resolution_status', 'open')->count(),
            'can_manage' => $canManage,
            'resolutions' => $this->resolutionOptions(),
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $issue = DB::table('transfer_discrepancies')->where('id', $id)->lockForUpdate()->first();
            abort_unless($issue, 404);

            $transfer = Transfer::whereNull('deleted_at')->findOrFail($issue->transfer_id);
            $detail = TransferDetail::findOrFail($issue->transfer_detail_id);
            $resolutionCode = (string) $request->input('resolution_code');

            $request->attributes->set('prodex_transfer_issue_type', (string) $issue->type);
            $request->attributes->set('prodex_transfer_issue_id', (int) $issue->id);

            // The legacy discrepancy controller authorizes by warehouse. For a modern
            // transfer, grant those compatibility IDs only inside this one request and
            // only when the actor genuinely has access to one of its physical locations.
            $user = $request->user('api');
            if ($user && ($transfer->from_inventory_location_id || $transfer->to_inventory_location_id)) {
                $scope = app(InventoryLocationScopeService::class);
                $canTouch = ($transfer->from_inventory_location_id && $scope->canAccess($user, (int) $transfer->from_inventory_location_id))
                    || ($transfer->to_inventory_location_id && $scope->canAccess($user, (int) $transfer->to_inventory_location_id));

                if ($canTouch) {
                    $request->attributes->set('prodex_transfer_authorized_warehouse_ids', array_values(array_filter([
                        $transfer->from_warehouse_id ? (int) $transfer->from_warehouse_id : null,
                        $transfer->to_warehouse_id ? (int) $transfer->to_warehouse_id : null,
                    ])));
                }
            }

            $quarantineLocationId = null;
            $beforeQuarantineMaxId = 0;
            if ($transfer->to_inventory_location_id && $issue->type === 'defective') {
                $quarantineLocationId = DB::table('transfer_quarantine_stock')
                    ->where('transfer_id', $transfer->id)
                    ->where('transfer_detail_id', $detail->id)
                    ->where('status', 'quarantined')
                    ->whereNotNull('inventory_location_id')
                    ->orderBy('id')
                    ->value('inventory_location_id');

                $beforeQuarantineMaxId = (int) (DB::table('transfer_quarantine_stock')->max('id') ?? 0);
            }

            $response = parent::resolve($request, $id);

            if ($transfer->to_inventory_location_id) {
                if ($quarantineLocationId && $beforeQuarantineMaxId > 0) {
                    DB::table('transfer_quarantine_stock')
                        ->where('id', '>', $beforeQuarantineMaxId)
                        ->where('transfer_id', $transfer->id)
                        ->where('transfer_detail_id', $detail->id)
                        ->whereNull('inventory_location_id')
                        ->update([
                            'inventory_location_id' => (int) $quarantineLocationId,
                            'updated_at' => now(),
                        ]);
                }

                app(TransferIssueLocationResolutionService::class)->apply(
                    $issue,
                    $transfer,
                    $detail,
                    $resolutionCode,
                    optional($user)->id,
                    $quarantineLocationId ? (int) $quarantineLocationId : null
                );
            }

            return $response;
        }, 5);
    }

    private function resolutionOptions(): array
    {
        return [
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
        ];
    }
}
