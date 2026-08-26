<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\CashRegister;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Native POS register audit report.
 *
 * New sessions are scoped by Branch + InventoryLocation + optional CashDrawer.
 * warehouse_id is used only as a fallback for historical sessions that predate
 * the native POS location model.
 */
class PosCashRegisterReportController extends CashRegisterController
{
    public function report(Request $request)
    {
        $branchScope = app(BranchScopeService::class);
        $locationScope = app(InventoryLocationScopeService::class);

        $this->authorizeForUser($request->user('api'), 'cash_register_report', Sale::class);

        $user = Auth::user();
        $today = Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : $today->copy()->subDays(6)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : $today->toDateString();
        if ($from > $to) $from = $to;

        $perPage = (int) ($request->limit ?: 10);
        $perPage = $perPage === -1 ? -1 : max(1, min($perPage, 200));
        $page = max(1, (int) $request->get('page', 1));
        $sort = $request->SortField ?: 'closed_at';
        $direction = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = [
            'id', 'opened_at', 'closed_at', 'opening_balance', 'total_sales', 'difference',
            'expected_cash', 'counted_cash', 'cash_difference', 'closing_status', 'status',
            'branch_id', 'inventory_location_id', 'cash_drawer_id', 'user_id',
        ];
        if (! in_array($sort, $allowedSorts, true)) $sort = 'closed_at';

        $allowedBranchIds = $branchScope->allowedBranchIds($user);
        $allowedLocationIds = $locationScope->allowedLocationIds($user);
        $legacyWarehouseIds = (int) $user->role_id === 1
            ? Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();

        $query = CashRegister::with([
                'user:id,firstname,lastname,username',
                'branch:id,name,code',
                'inventoryLocation:id,branch_id,name,code,type',
                'warehouse:id,name',
                'cashDrawer:id,name,code,branch_id,inventory_location_id',
            ])
            ->where('status', 'closed')
            ->whereDate('closed_at', '>=', $from)
            ->whereDate('closed_at', '<=', $to);

        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        } elseif ((int) $user->role_id !== 1) {
            $query->where(function ($scope) use ($allowedBranchIds, $allowedLocationIds, $legacyWarehouseIds) {
                $scope->where(function ($native) use ($allowedBranchIds, $allowedLocationIds) {
                    $native->whereNotNull('inventory_location_id');
                    if ($allowedLocationIds) $native->whereIn('inventory_location_id', $allowedLocationIds);
                    else $native->whereRaw('1 = 0');
                    if ($allowedBranchIds) $native->whereIn('branch_id', $allowedBranchIds);
                })->orWhere(function ($legacy) use ($legacyWarehouseIds) {
                    $legacy->whereNull('inventory_location_id');
                    if ($legacyWarehouseIds) $legacy->whereIn('warehouse_id', $legacyWarehouseIds);
                    else $legacy->whereRaw('1 = 0');
                });
            });
        }

        if ($request->filled('register_id')) $query->where('id', (int) $request->register_id);
        if ($request->filled('user_id')) $query->where('user_id', (int) $request->user_id);
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhere('branch_id_snapshot', $branchId);
            });
        }
        if ($request->filled('inventory_location_id')) {
            $locationId = (int) $request->inventory_location_id;
            $query->where(function ($q) use ($locationId) {
                $q->where('inventory_location_id', $locationId)->orWhere('inventory_location_id_snapshot', $locationId);
            });
        }
        if ($request->filled('cash_drawer_id')) $query->where('cash_drawer_id', (int) $request->cash_drawer_id);
        if ($request->filled('closing_status')) $query->where('closing_status', $request->closing_status);

        // Historical compatibility filter: shown separately and never used as the
        // operational selector for native POS sessions.
        if ($request->filled('legacy_warehouse_id')) {
            $warehouseId = (int) $request->legacy_warehouse_id;
            $query->where(function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId)->orWhere('warehouse_id_snapshot', $warehouseId);
            });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('id', 'like', $like)
                    ->orWhere('register_number_snapshot', 'like', $like)
                    ->orWhere('opened_by_user_name_snapshot', 'like', $like)
                    ->orWhere('closed_by_user_name_snapshot', 'like', $like)
                    ->orWhere('branch_name_snapshot', 'like', $like)
                    ->orWhere('inventory_location_name_snapshot', 'like', $like)
                    ->orWhere('cash_drawer_name_snapshot', 'like', $like)
                    ->orWhere('warehouse_name_snapshot', 'like', $like)
                    ->orWhere('closing_status', 'like', $like)
                    ->orWhereHas('user', fn ($uq) => $uq->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like)->orWhere('username', 'like', $like))
                    ->orWhereHas('branch', fn ($bq) => $bq->where('name', 'like', $like)->orWhere('code', 'like', $like))
                    ->orWhereHas('inventoryLocation', fn ($lq) => $lq->where('name', 'like', $like)->orWhere('code', 'like', $like))
                    ->orWhereHas('cashDrawer', fn ($dq) => $dq->where('name', 'like', $like)->orWhere('code', 'like', $like));
            });
        }

        $totalRows = $query->count();
        $items = $perPage === -1
            ? $query->orderBy($sort, $direction)->get()
            : $query->orderBy($sort, $direction)->forPage($page, $perPage)->get();

        $registers = $items->map(function (CashRegister $r) {
            $branchName = $r->branch_name_snapshot ?: optional($r->branch)->name;
            $locationName = $r->inventory_location_name_snapshot ?: optional($r->inventoryLocation)->name;
            $warehouseName = $r->warehouse_name_snapshot ?: optional($r->warehouse)->name;
            $drawerName = $r->cash_drawer_name_snapshot ?: optional($r->cashDrawer)->name;
            $drawerCode = $r->cash_drawer_code_snapshot ?: optional($r->cashDrawer)->code;
            $methods = $r->sales_by_payment_method ?: [];
            $closingStatus = $r->closing_status ?: $this->closingAuditStatus((float) ($r->cash_difference ?? $r->difference ?? 0));

            return [
                'id' => $r->id,
                'register_number' => $r->register_number_snapshot ?: 'Register #'.$r->id,
                'user_id' => $r->user_id,
                'cashier_name' => $r->opened_by_user_name_snapshot ?: $this->userDisplayName($r->user),
                'opened_by_user_id' => $r->opened_by_user_id_snapshot ?: $r->user_id,
                'opened_by_user_name' => $r->opened_by_user_name_snapshot ?: $this->userDisplayName($r->user),
                'closed_by_user_id' => $r->closed_by_user_id,
                'closed_by_user_name' => $r->closed_by_user_name_snapshot,
                'branch_id' => $r->branch_id_snapshot ?: $r->branch_id,
                'branch_name' => $branchName,
                'inventory_location_id' => $r->inventory_location_id_snapshot ?: $r->inventory_location_id,
                'inventory_location_name' => $locationName,
                'cash_drawer_id' => $r->cash_drawer_id,
                'cash_drawer_name' => $drawerName,
                'cash_drawer_code' => $drawerCode,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_snapshot_id' => $r->warehouse_id_snapshot ?: $r->warehouse_id,
                'warehouse_name' => $warehouseName,
                'is_legacy_context' => ! $r->inventory_location_id && ! $r->inventory_location_id_snapshot,
                'operational_context_label' => $locationName
                    ? trim(($branchName ? $branchName.' · ' : '').$locationName)
                    : ($warehouseName ? 'Histórico · '.$warehouseName : 'Contexto histórico'),
                'tenant_id' => $r->tenant_id_snapshot,
                'opened_at' => optional($r->opened_at)->format('Y-m-d H:i:s'),
                'closed_at' => $r->closed_at ? $r->closed_at->format('Y-m-d H:i:s') : null,
                'opened_date' => $r->opened_date_snapshot ?: optional($r->opened_at)->toDateString(),
                'opened_time' => $r->opened_time_snapshot ?: optional($r->opened_at)->format('H:i:s'),
                'closed_date' => $r->closed_date_snapshot ?: optional($r->closed_at)->toDateString(),
                'closed_time' => $r->closed_time_snapshot ?: optional($r->closed_at)->format('H:i:s'),
                'session_duration_seconds' => $r->session_duration_seconds,
                'session_duration_human' => $this->formatDuration($r->session_duration_seconds),
                'status' => $r->status,
                'closing_status' => $closingStatus,
                'closing_status_label' => $this->closingAuditStatusLabel($closingStatus),
                'opening_balance' => number_format((float) $r->opening_balance, 2, '.', ''),
                'cash_in' => number_format((float) $r->cash_in, 2, '.', ''),
                'cash_out' => number_format((float) $r->cash_out, 2, '.', ''),
                'total_sales' => number_format((float) $r->total_sales, 2, '.', ''),
                'closing_balance' => is_null($r->closing_balance) ? null : number_format((float) $r->closing_balance, 2, '.', ''),
                'sales_by_payment_method' => $methods,
                'cash_sales' => number_format($this->paymentCategoryTotal($methods, 'cash'), 2, '.', ''),
                'card_sales' => number_format((float) ($r->card_system_total ?? $this->paymentCategoryTotal($methods, 'card')), 2, '.', ''),
                'transfer_sales' => number_format((float) ($r->transfer_total ?? $this->paymentCategoryTotal($methods, 'transfer')), 2, '.', ''),
                'other_sales' => number_format($this->paymentCategoryTotal($methods, 'other', true), 2, '.', ''),
                'expected_cash' => is_null($r->expected_cash) ? null : number_format((float) $r->expected_cash, 2, '.', ''),
                'counted_cash' => is_null($r->counted_cash) ? null : number_format((float) $r->counted_cash, 2, '.', ''),
                'difference' => is_null($r->cash_difference ?? $r->difference) ? null : number_format((float) ($r->cash_difference ?? $r->difference), 2, '.', ''),
                'counted_denominations' => $r->counted_denominations ?: [],
                'card_system_total' => number_format((float) ($r->card_system_total ?? 0), 2, '.', ''),
                'card_terminal_total' => is_null($r->card_terminal_total) ? null : number_format((float) $r->card_terminal_total, 2, '.', ''),
                'card_difference' => is_null($r->card_difference) ? null : number_format((float) $r->card_difference, 2, '.', ''),
                'card_batch_number' => $r->card_batch_number,
                'card_reference' => $r->card_reference,
                'card_notes' => $r->card_notes,
                'transfer_total' => number_format((float) ($r->transfer_total ?? 0), 2, '.', ''),
                'transfers_verified' => (bool) $r->transfers_verified,
                'transfer_notes' => $r->transfer_notes,
                'cash_withdrawn_at_close' => is_null($r->cash_withdrawn_at_close) ? null : number_format((float) $r->cash_withdrawn_at_close, 2, '.', ''),
                'next_opening_float' => is_null($r->next_opening_float) ? null : number_format((float) $r->next_opening_float, 2, '.', ''),
                'notes' => $r->notes,
                'closing_snapshot' => $r->closing_snapshot ?: [],
            ];
        })->values();

        $branches = Branch::whereNull('deleted_at')->where('is_active', true)
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $allowedBranchIds ?: [0]))
            ->orderBy('name')->get(['id', 'name', 'code']);

        $locations = InventoryLocation::active()
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $allowedLocationIds ?: [0]))
            ->orderBy('branch_id')->orderBy('name')->get(['id', 'branch_id', 'name', 'code', 'type']);

        $drawers = CashDrawer::whereNull('deleted_at')->where('is_active', true)
            ->when((int) $user->role_id !== 1, function ($q) use ($allowedBranchIds, $allowedLocationIds) {
                $q->where(function ($scope) use ($allowedBranchIds, $allowedLocationIds) {
                    if ($allowedLocationIds) $scope->whereIn('inventory_location_id', $allowedLocationIds);
                    if ($allowedBranchIds) $scope->orWhereIn('branch_id', $allowedBranchIds);
                });
            })
            ->orderBy('name')->get(['id', 'name', 'code', 'branch_id', 'inventory_location_id']);

        $users = User::whereNull('deleted_at')
            ->when(! $user->hasRecordView(), fn ($q) => $q->whereKey($user->id))
            ->orderBy('firstname')->orderBy('lastname')->get(['id', 'username', 'firstname', 'lastname']);

        $legacyWarehouses = Warehouse::whereNull('deleted_at')
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $legacyWarehouseIds ?: [0]))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'registers' => $registers,
            'users' => $users,
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $drawers,
            'legacy_warehouses' => $legacyWarehouses,
        ]);
    }
}
