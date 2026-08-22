<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Services\TransferBusinessDestinationService;
use App\Services\TransferListScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class FinalTransferController extends TransferController
{
    public function store(Request $request)
    {
        $this->assertBusinessRoute($request);
        return parent::store($request);
    }

    public function update(Request $request, $id)
    {
        $this->assertBusinessRoute($request);
        return parent::update($request, $id);
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Transfer::class);
        $user = Auth::user() ?: $user;

        $query = Transfer::with([
            'from_warehouse:id,name',
            'to_warehouse:id,name',
        ])->whereNull('deleted_at');

        $hasLocations = Schema::hasTable('inventory_locations')
            && Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

        if ($hasLocations) {
            $query->with([
                'fromInventoryLocation.branch:id,name',
                'fromInventoryLocation.warehouse:id,name',
                'toInventoryLocation.branch:id,name',
                'toInventoryLocation.warehouse:id,name',
            ]);
        }

        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        }

        app(TransferListScopeService::class)->apply($query, $user);

        if ($request->filled('Ref')) {
            $query->where('Ref', 'like', '%'.$request->string('Ref')->toString().'%');
        }
        if ($request->filled('statut')) {
            $query->where('statut', 'like', '%'.$request->string('statut')->toString().'%');
        }

        if ($request->filled('from_warehouse_id')) {
            $value = (int) $request->input('from_warehouse_id');
            if ($hasLocations) $query->where('from_inventory_location_id', $value);
            else $query->where('from_warehouse_id', $value);
        }
        if ($request->filled('to_warehouse_id')) {
            $value = (int) $request->input('to_warehouse_id');
            if ($hasLocations) $query->where('to_inventory_location_id', $value);
            else $query->where('to_warehouse_id', $value);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($scope) use ($search, $hasLocations) {
                $scope->where('Ref', 'like', "%{$search}%")
                    ->orWhere('statut', 'like', "%{$search}%")
                    ->orWhereHas('from_warehouse', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('to_warehouse', fn ($q) => $q->where('name', 'like', "%{$search}%"));

                if ($hasLocations) {
                    $scope->orWhereHas('fromInventoryLocation', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('toInventoryLocation', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('fromInventoryLocation.branch', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('toInventoryLocation.branch', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                }
            });
        }

        $totalRows = (clone $query)->count();
        $limit = (int) $request->input('limit', 10);
        $page = max(1, (int) $request->input('page', 1));
        if ($limit === -1) $limit = max(1, $totalRows);
        if ($limit <= 0) $limit = 10;

        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'Ref', 'GrandTotal', 'items', 'statut', 'approval_status', 'from_warehouse_id', 'to_warehouse_id'];
        if (! in_array($sortField, $allowedSorts, true)) $sortField = 'id';

        if ($hasLocations && $sortField === 'from_warehouse_id') {
            $query->orderByRaw('COALESCE(from_inventory_location_id, from_warehouse_id) '.$sortType);
        } elseif ($hasLocations && $sortField === 'to_warehouse_id') {
            $query->orderByRaw('COALESCE(to_inventory_location_id, to_warehouse_id) '.$sortType);
        } else {
            $query->orderBy($sortField, $sortType);
        }

        $service = app(TransferListScopeService::class);
        $transfers = $query
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(fn (Transfer $transfer) => [
                'id' => (int) $transfer->id,
                'date' => trim((string) $transfer->date.' '.(string) $transfer->time),
                'Ref' => $transfer->Ref,
                'from_warehouse' => $service->transferLabel($transfer, 'from'),
                'to_warehouse' => $service->transferLabel($transfer, 'to'),
                'GrandTotal' => (float) $transfer->GrandTotal,
                'items' => (float) $transfer->items,
                'statut' => $transfer->statut,
                'approval_status' => $transfer->approval_status,
                'from_inventory_location_id' => $transfer->from_inventory_location_id ? (int) $transfer->from_inventory_location_id : null,
                'to_inventory_location_id' => $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null,
            ])->values();

        return response()->json([
            'totalRows' => $totalRows,
            'warehouses' => $service->sourceOptions($user)->values(),
            'transfers' => $transfers,
            'inventory_location_mode' => $hasLocations,
        ]);
    }

    private function assertBusinessRoute(Request $request): void
    {
        $from = $request->input('transfer.from_inventory_location_id');
        $to = $request->input('transfer.to_inventory_location_id');

        // Legacy warehouse-only payloads remain supported during the rollout.
        if (! $from || ! $to) return;

        app(TransferBusinessDestinationService::class)->assertAllowed((int) $from, (int) $to);
    }
}
