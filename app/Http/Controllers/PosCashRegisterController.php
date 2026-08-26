<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\PaymentSaleReturns;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StoreCreditVoucherTransaction;
use App\Services\UserOperationalAssignmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * POS-native register sessions.
 *
 * Operational identity is Branch + InventoryLocation + optional CashDrawer.
 * warehouse_id is written only as a legacy compatibility pointer when one exists.
 */
class PosCashRegisterController extends CashRegisterController
{
    public function openRegister(Request $request, UserOperationalAssignmentService $assignmentService)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'inventory_location_id' => 'nullable|integer|exists:inventory_locations,id',
            'cash_drawer_id' => 'nullable|integer|exists:cash_drawers,id',
            'opening_balance' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $user = Auth::user();
        $effective = $assignmentService->effectiveAssignment($user);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : (int) ($effective['branch_id'] ?? 0);
        $locationId = isset($data['inventory_location_id']) ? (int) $data['inventory_location_id'] : (int) ($effective['inventory_location_id'] ?? 0);
        $cashDrawerId = isset($data['cash_drawer_id']) && $data['cash_drawer_id'] !== null
            ? (int) $data['cash_drawer_id']
            : ($effective['cash_drawer_id'] ? (int) $effective['cash_drawer_id'] : null);

        $assignmentService->validateRequestedOperationalAssignment(
            $user,
            $branchId ?: null,
            $locationId ?: null,
            $cashDrawerId,
            false
        );

        $warehouseId = $effective['warehouse_id'] ? (int) $effective['warehouse_id'] : null;
        if ($cashDrawerId) {
            $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
            if ($drawer && $drawer->warehouse_id) {
                $warehouseId = (int) $drawer->warehouse_id;
            }
        }

        $existing = CashRegister::where('user_id', $user->id)
            ->where('branch_id', $branchId)
            ->where('inventory_location_id', $locationId)
            ->when($cashDrawerId, fn ($q) => $q->where('cash_drawer_id', $cashDrawerId))
            ->when(! $cashDrawerId, fn ($q) => $q->whereNull('cash_drawer_id'))
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Register already open'], 409);
        }

        $register = CashRegister::create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'inventory_location_id' => $locationId,
            'warehouse_id' => $warehouseId,
            'cash_drawer_id' => $cashDrawerId,
            'opening_balance' => $data['opening_balance'],
            'status' => 'open',
            'opened_at' => Carbon::now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'register' => $register->load('branch', 'inventoryLocation', 'warehouse', 'cashDrawer'),
        ]);
    }

    public function getCurrentRegister(Request $request, $userId)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);
        $user = Auth::user();
        if (! $user->hasPermissionName('cash_register_override_assignment') && (int) $userId !== (int) $user->id) {
            abort(403);
        }

        $branchId = $request->integer('branch_id') ?: null;
        $locationId = $request->integer('inventory_location_id') ?: null;
        $cashDrawerId = $request->integer('cash_drawer_id') ?: null;

        if (! $branchId || ! $locationId) {
            $effective = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);
            $branchId = $branchId ?: ($effective['branch_id'] ? (int) $effective['branch_id'] : null);
            $locationId = $locationId ?: ($effective['inventory_location_id'] ? (int) $effective['inventory_location_id'] : null);
            $cashDrawerId = $cashDrawerId ?: ($effective['cash_drawer_id'] ? (int) $effective['cash_drawer_id'] : null);
        }

        $query = CashRegister::with('user', 'branch', 'inventoryLocation', 'warehouse', 'cashDrawer')
            ->where('user_id', $userId)
            ->where('status', 'open');

        if ($branchId) $query->where('branch_id', $branchId);
        if ($locationId) $query->where('inventory_location_id', $locationId);
        if ($cashDrawerId) $query->where('cash_drawer_id', $cashDrawerId);

        $register = $query->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'register' => $register,
            'closing_summary' => $register ? $this->buildClosingSummary($register) : null,
        ]);
    }

    public function closeRegister(Request $request, UserOperationalAssignmentService $assignmentService)
    {
        $response = parent::closeRegister($request, $assignmentService);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $request->filled('register_id')) {
            $register = CashRegister::with('branch', 'inventoryLocation')->find($request->integer('register_id'));
            if ($register) {
                $register->branch_id_snapshot = $register->branch_id;
                $register->branch_name_snapshot = optional($register->branch)->name;
                $register->inventory_location_id_snapshot = $register->inventory_location_id;
                $register->inventory_location_name_snapshot = optional($register->inventoryLocation)->name;
                $register->save();
            }
        }

        return $response;
    }

    protected function buildClosingSummary(CashRegister $register, ?Carbon $to = null): array
    {
        $from = $register->opened_at;
        $to = $to ?: Carbon::now();

        $methods = PaymentMethod::whereNull('deleted_at')->orderBy('id')->get(['id', 'name']);

        $paymentRows = PaymentSale::query()
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->leftJoin('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.is_pos', 1)
            ->where('sales.user_id', $register->user_id)
            ->whereBetween('sales.created_at', [$from, $to]);
        $this->scopeSalesToRegister($paymentRows, $register, 'sales');
        $paymentRows = $paymentRows
            ->groupBy('payment_sales.payment_method_id', 'payment_methods.name')
            ->select(
                'payment_sales.payment_method_id',
                DB::raw("COALESCE(payment_methods.name, 'Unknown') as name"),
                DB::raw('SUM(payment_sales.montant) as total')
            )
            ->get();

        $configuredIds = $methods->pluck('id')->map(fn ($id) => (string) $id)->all();
        $salesByMethod = $methods->map(function ($method) use ($paymentRows) {
            $row = $paymentRows->firstWhere('payment_method_id', $method->id);
            return [
                'id' => $method->id,
                'name' => $method->name,
                'category' => $this->paymentMethodCategory($method->name),
                'total' => round((float) ($row->total ?? 0), 2),
            ];
        })->values()->all();

        foreach ($paymentRows as $row) {
            if ($row->payment_method_id !== null && in_array((string) $row->payment_method_id, $configuredIds, true)) continue;
            $name = $row->name ?: 'Unknown';
            $salesByMethod[] = [
                'id' => $row->payment_method_id,
                'name' => $name,
                'category' => $this->paymentMethodCategory($name),
                'total' => round((float) $row->total, 2),
            ];
        }

        $cashMethodIds = collect($salesByMethod)->where('category', 'cash')->pluck('id')->filter()->values()->all();
        $cashRefunds = 0;
        if ($cashMethodIds) {
            $refunds = PaymentSaleReturns::query()
                ->join('sale_returns', 'payment_sale_returns.sale_return_id', '=', 'sale_returns.id')
                ->whereNull('payment_sale_returns.deleted_at')
                ->whereNull('sale_returns.deleted_at')
                ->where('sale_returns.user_id', $register->user_id)
                ->whereIn('payment_sale_returns.payment_method_id', $cashMethodIds)
                ->whereBetween('sale_returns.created_at', [$from, $to]);
            $this->scopeReturnsToRegister($refunds, $register, 'sale_returns');
            $cashRefunds = $refunds->sum('payment_sale_returns.montant');
        }

        $salesQuery = Sale::whereNull('deleted_at')
            ->where('is_pos', 1)
            ->where('user_id', $register->user_id)
            ->whereBetween('created_at', [$from, $to]);
        $this->scopeSalesToRegister($salesQuery, $register);
        $totalSales = (clone $salesQuery)->sum('GrandTotal');
        $transactionCount = (clone $salesQuery)->count();

        $cashSales = collect($salesByMethod)->where('category', 'cash')->sum('total');
        $cardTotal = collect($salesByMethod)->where('category', 'card')->sum('total');
        $transferTotal = collect($salesByMethod)->where('category', 'transfer')->sum('total');

        $storeCreditApplied = 0;
        if (Schema::hasTable('store_credit_voucher_transactions')) {
            $creditQuery = StoreCreditVoucherTransaction::query()
                ->join('sales', 'store_credit_voucher_transactions.sale_id', '=', 'sales.id')
                ->where('store_credit_voucher_transactions.type', 'redeem')
                ->whereNull('sales.deleted_at')
                ->where('sales.is_pos', 1)
                ->where('sales.user_id', $register->user_id)
                ->whereBetween('sales.created_at', [$from, $to]);
            $this->scopeSalesToRegister($creditQuery, $register, 'sales');
            $storeCreditApplied = $creditQuery->sum('store_credit_voucher_transactions.amount');
        } else {
            Log::warning('POS register summary skipped store credit totals because tenant schema is missing store_credit_voucher_transactions.', [
                'register_id' => $register->id,
            ]);
        }

        if ((float) $storeCreditApplied > 0) {
            $salesByMethod[] = [
                'id' => null,
                'name' => 'Crédito de tienda / Vale aplicado',
                'category' => 'store_credit',
                'total' => round((float) $storeCreditApplied, 2),
            ];
        }

        $expectedCash = (float) ($register->opening_balance ?? 0)
            + (float) $cashSales
            + (float) ($register->cash_in ?? 0)
            - (float) ($register->cash_out ?? 0)
            - (float) $cashRefunds;

        return [
            'cashier' => optional($register->user)->username ?: trim(optional($register->user)->firstname.' '.optional($register->user)->lastname),
            // Compatibility key consumed by the current POS close modal.
            'warehouse' => optional($register->inventoryLocation)->name ?: optional($register->warehouse)->name,
            'branch' => optional($register->branch)->name,
            'inventory_location' => optional($register->inventoryLocation)->name,
            'branch_id' => $register->branch_id,
            'inventory_location_id' => $register->inventory_location_id,
            'cash_drawer_id' => $register->cash_drawer_id,
            'opened_at' => optional($register->opened_at)->format('Y-m-d H:i:s'),
            'current_time' => $to->format('Y-m-d H:i:s'),
            'opening_balance' => round((float) ($register->opening_balance ?? 0), 2),
            'total_sales' => round((float) $totalSales, 2),
            'transaction_count' => $transactionCount,
            'sales_by_payment_method' => $salesByMethod,
            'cash_sales' => round((float) $cashSales, 2),
            'cash_additions' => round((float) ($register->cash_in ?? 0), 2),
            'cash_withdrawals' => round((float) ($register->cash_out ?? 0), 2),
            'cash_refunds' => round((float) $cashRefunds, 2),
            'expected_cash' => round((float) $expectedCash, 2),
            'card_system_total' => round((float) $cardTotal, 2),
            'transfer_total' => round((float) $transferTotal, 2),
            'store_credit_applied' => round((float) $storeCreditApplied, 2),
            'denominations' => $this->cashDenominations(),
        ];
    }

    protected function buildSessionIdentitySnapshot(CashRegister $register, Carbon $closedAt, $closedByUser): array
    {
        $identity = parent::buildSessionIdentitySnapshot($register, $closedAt, $closedByUser);
        $identity['branch_id'] = $register->branch_id;
        $identity['branch_name'] = optional($register->branch)->name;
        $identity['inventory_location_id'] = $register->inventory_location_id;
        $identity['inventory_location_name'] = optional($register->inventoryLocation)->name;
        $identity['operational_location'] = optional($register->inventoryLocation)->name ?: $identity['warehouse_name'];
        return $identity;
    }

    private function scopeSalesToRegister($query, CashRegister $register, string $prefix = ''): void
    {
        $column = fn (string $name) => $prefix ? $prefix.'.'.$name : $name;
        if ($register->inventory_location_id && Schema::hasColumn('sales', 'inventory_location_id')) {
            $query->where($column('inventory_location_id'), $register->inventory_location_id);
            if ($register->branch_id && Schema::hasColumn('sales', 'branch_id')) {
                $query->where($column('branch_id'), $register->branch_id);
            }
            if ($register->cash_drawer_id && Schema::hasColumn('sales', 'cash_drawer_id')) {
                $query->where($column('cash_drawer_id'), $register->cash_drawer_id);
            }
            return;
        }

        if ($register->warehouse_id) {
            $query->where($column('warehouse_id'), $register->warehouse_id);
        }
    }

    private function scopeReturnsToRegister($query, CashRegister $register, string $prefix = ''): void
    {
        $column = fn (string $name) => $prefix ? $prefix.'.'.$name : $name;
        if ($register->inventory_location_id && Schema::hasColumn('sale_returns', 'inventory_location_id')) {
            $query->where($column('inventory_location_id'), $register->inventory_location_id);
            if ($register->branch_id && Schema::hasColumn('sale_returns', 'branch_id')) {
                $query->where($column('branch_id'), $register->branch_id);
            }
            return;
        }

        if ($register->warehouse_id) {
            $query->where($column('warehouse_id'), $register->warehouse_id);
        }
    }
}
