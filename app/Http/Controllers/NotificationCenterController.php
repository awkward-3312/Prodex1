<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationCenterController extends Controller
{
    public function index(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user('api');
        abort_unless($user, 401);

        $items = collect();
        $unread = 0;

        if (Schema::hasTable('transfer_notifications') && Schema::hasTable('transfers')) {
            $transfers = DB::table('transfer_notifications as n')
                ->join('transfers as t', 't.id', '=', 'n.transfer_id')
                ->where('n.user_id', $user->id)
                ->whereNull('t.deleted_at')
                ->orderByDesc('n.created_at')
                ->limit(20)
                ->get(['n.id','n.transfer_id','n.title','n.message','n.read_at','n.created_at','t.Ref as reference','t.logistics_status']);

            foreach ($transfers as $row) {
                if (! $row->read_at) $unread++;
                $items->push([
                    'key' => 'transfer:'.$row->id,
                    'category' => 'transfers',
                    'title' => trim(($row->title ?: 'Transferencia').($row->reference ? ' · '.$row->reference : '')),
                    'message' => $row->message ?: 'Hay una actualización de transferencia.',
                    'unread' => ! $row->read_at,
                    'created_at' => $row->created_at,
                    'action' => '/app/transfers/detail/'.$row->transfer_id,
                    'read_endpoint' => '/api/transfer-logistics/notifications/'.$row->id.'/read',
                ]);
            }
        }

        if (Schema::hasTable('transfer_discrepancies') && Schema::hasTable('transfers')) {
            $canSeeIssues = (int) $user->role_id === 1
                || $user->hasPermissionName('transfer_issue_manage')
                || $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION)
                || $user->hasPermissionName('transfer_view')
                || $user->hasPermissionName('damage_view')
                || $user->hasPermissionName('product_view');

            if ($canSeeIssues) {
                $warehouseIds = app(TransferLogisticsService::class)->warehouseIdsForUser($user);
                $locationIds = Schema::hasTable('inventory_locations') ? app(InventoryLocationScopeService::class)->allowedLocationIds($user) : [];
                $hasLocations = Schema::hasColumn('transfers', 'from_inventory_location_id') && Schema::hasColumn('transfers', 'to_inventory_location_id');

                $query = DB::table('transfer_discrepancies as d')
                    ->join('transfers as t', 't.id', '=', 'd.transfer_id')
                    ->whereNull('t.deleted_at')->where('d.resolution_status', 'open');

                if ((int) $user->role_id !== 1) {
                    $query->where(function ($scope) use ($warehouseIds, $locationIds, $hasLocations) {
                        if ($hasLocations && $locationIds) {
                            $scope->whereIn('t.to_inventory_location_id', $locationIds)->orWhereIn('t.from_inventory_location_id', $locationIds);
                        }
                        if ($warehouseIds) {
                            $scope->orWhere(function ($legacy) use ($warehouseIds, $hasLocations) {
                                if ($hasLocations) $legacy->whereNull('t.from_inventory_location_id')->whereNull('t.to_inventory_location_id');
                                $legacy->where(function ($w) use ($warehouseIds) { $w->whereIn('t.to_warehouse_id', $warehouseIds)->orWhereIn('t.from_warehouse_id', $warehouseIds); });
                            });
                        }
                    });
                }

                $open = $query->orderByDesc('d.reported_at')->limit(20)->get(['d.id','d.type','d.quantity','d.reported_at','t.Ref as reference']);
                foreach ($open as $row) {
                    $items->push([
                        'key' => 'issue:'.$row->id,
                        'category' => 'inventory',
                        'title' => ($row->type === 'missing' ? 'Faltante' : 'Producto defectuoso').' · '.$row->reference,
                        'message' => 'Incidencia abierta por '.number_format((float) $row->quantity, 3, '.', ',').' unidad(es).',
                        'unread' => false,
                        'created_at' => $row->reported_at,
                        'action' => '/app/inventory/missing',
                        'read_endpoint' => null,
                    ]);
                }
            }
        }

        return response()->json([
            'notifications' => $items->sortByDesc('created_at')->take(30)->values(),
            'unread' => $unread,
            'categories' => ['inventory' => 'Inventario', 'transfers' => 'Transferencias'],
        ]);
    }
}
