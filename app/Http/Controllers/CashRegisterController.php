<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\PaymentSaleReturns;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StoreCreditVoucherTransaction;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CashRegisterController extends BaseController
{
    public function openRegister(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'opening_balance' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $user_id = Auth::user()->id;

        $existing = CashRegister::where('user_id', $user_id)
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('status', 'open')
            ->first();
        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Register already open'], 409);
        }

        $register = CashRegister::create([
            'user_id' => $user_id,
            'warehouse_id' => $data['warehouse_id'],
            'opening_balance' => $data['opening_balance'],
            'status' => 'open',
            'opened_at' => Carbon::now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['success' => true, 'register' => $register]);
    }

    public function getCurrentRegister(Request $request, $userId)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $warehouseId = $request->query('warehouse_id');
        $query = CashRegister::with('user', 'warehouse')
            ->where('user_id', $userId)
            ->where('status', 'open');
        // If a specific warehouse is selected, filter; otherwise return the latest open register across warehouses
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        $register = $query->orderByDesc('id')->first();

        return response()->json([
            'success' => true,
            'register' => $register,
            'closing_summary' => $register ? $this->buildClosingSummary($register) : null,
        ]);
    }

    public function cashInOut(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'register_id' => 'required|integer|exists:cash_registers,id',
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $register = CashRegister::findOrFail($data['register_id']);
        if ($register->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Register is closed'], 409);
        }

        if ($data['type'] === 'in') {
            $register->cash_in = ($register->cash_in ?? 0) + $data['amount'];
        } else {
            $register->cash_out = ($register->cash_out ?? 0) + $data['amount'];
        }
        if (! empty($data['notes'])) {
            $register->notes = trim($register->notes."\n".'['.Carbon::now()->toDateTimeString().'] Cash '.$data['type'].': '.number_format($data['amount'], 2));
        }
        $register->save();

        return response()->json(['success' => true, 'register' => $register]);
    }

    public function closeRegister(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'register_id' => 'required|integer|exists:cash_registers,id',
            'counted_cash' => 'required|numeric',
            'closing_balance' => 'nullable|numeric',
            'cash_withdrawn_at_close' => 'nullable|numeric|min:0',
            'next_opening_float' => 'nullable|numeric|min:0',
            'counted_denominations' => 'nullable|array',
            'card_terminal_total' => 'nullable|numeric',
            'card_batch_number' => 'nullable|string|max:191',
            'card_reference' => 'nullable|string|max:191',
            'card_notes' => 'nullable|string',
            'transfers_verified' => 'nullable|boolean',
            'transfer_notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $register = CashRegister::findOrFail($data['register_id']);
        if ($register->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'Register already closed'], 409);
        }

        $now = Carbon::now();
        $summary = $this->buildClosingSummary($register, $now);
        $totalSales = (float) $summary['total_sales'];
        $expectedCash = (float) $summary['expected_cash'];
        $counted = (float) $data['counted_cash'];
        $difference = $counted - $expectedCash;
        $cardTerminalTotal = array_key_exists('card_terminal_total', $data) && $data['card_terminal_total'] !== null
            ? (float) $data['card_terminal_total']
            : null;
        $cardDifference = $cardTerminalTotal === null ? null : $cardTerminalTotal - (float) $summary['card_system_total'];
        $identity = $this->buildSessionIdentitySnapshot($register, $now, Auth::user());
        $closingStatus = $this->closingAuditStatus($difference);

        $register->closing_balance = $data['closing_balance'] ?? $counted;
        $register->cash_withdrawn_at_close = $data['cash_withdrawn_at_close'] ?? null;
        $register->next_opening_float = $data['next_opening_float'] ?? null;
        $register->total_sales = $totalSales;
        $register->difference = $difference;
        $register->counted_denominations = $data['counted_denominations'] ?? null;
        $register->sales_by_payment_method = $summary['sales_by_payment_method'];
        $register->expected_cash = $expectedCash;
        $register->counted_cash = $counted;
        $register->cash_difference = $difference;
        $register->card_system_total = $summary['card_system_total'];
        $register->card_terminal_total = $cardTerminalTotal;
        $register->card_difference = $cardDifference;
        $register->card_batch_number = $data['card_batch_number'] ?? null;
        $register->card_reference = $data['card_reference'] ?? null;
        $register->card_notes = $data['card_notes'] ?? null;
        $register->transfer_total = $summary['transfer_total'];
        $register->transfers_verified = $data['transfers_verified'] ?? false;
        $register->transfer_notes = $data['transfer_notes'] ?? null;
        $register->register_number_snapshot = $identity['register_number'];
        $register->opened_by_user_id_snapshot = $identity['opened_by_user_id'];
        $register->opened_by_user_name_snapshot = $identity['opened_by_user_name'];
        $register->closed_by_user_id = $identity['closed_by_user_id'];
        $register->closed_by_user_name_snapshot = $identity['closed_by_user_name'];
        $register->warehouse_id_snapshot = $identity['warehouse_id'];
        $register->warehouse_name_snapshot = $identity['warehouse_name'];
        $register->tenant_id_snapshot = $identity['tenant_id'];
        $register->opened_date_snapshot = $identity['opened_date'];
        $register->opened_time_snapshot = $identity['opened_time'];
        $register->closed_date_snapshot = $identity['closed_date'];
        $register->closed_time_snapshot = $identity['closed_time'];
        $register->session_duration_seconds = $identity['session_duration_seconds'];
        $register->closing_status = $closingStatus;
        $register->closing_snapshot = array_merge($summary, [
            'identity' => $identity,
            'counted_cash' => $counted,
            'cash_difference' => $difference,
            'closing_status' => $closingStatus,
            'closing_status_label' => $this->closingAuditStatusLabel($closingStatus),
            'counted_denominations' => $data['counted_denominations'] ?? null,
            'card_terminal_total' => $cardTerminalTotal,
            'card_difference' => $cardDifference,
            'card_batch_number' => $data['card_batch_number'] ?? null,
            'card_reference' => $data['card_reference'] ?? null,
            'card_notes' => $data['card_notes'] ?? null,
            'transfers_verified' => $data['transfers_verified'] ?? false,
            'transfer_notes' => $data['transfer_notes'] ?? null,
            'cash_withdrawn_at_close' => $data['cash_withdrawn_at_close'] ?? null,
            'next_opening_float' => $data['next_opening_float'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $register->status = 'closed';
        $register->closed_at = $now;
        
        if (! empty($data['notes'])) {
            $register->notes = trim(($register->notes ?? '')."\n".$data['notes']);
        }
        $register->save();

        return response()->json([
            'success' => true,
            'register' => $register,
            'summary' => [
                'expected_cash' => $expectedCash,
                'counted_cash' => $counted,
                'difference' => $difference,
                'card_difference' => $cardDifference,
            ],
        ]);
    }

    protected function buildClosingSummary(CashRegister $register, ?Carbon $to = null): array
    {
        $from = $register->opened_at;
        $to = $to ?: Carbon::now();

        $methods = PaymentMethod::whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        $paymentRows = PaymentSale::query()
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->leftJoin('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.is_pos', 1)
            ->where('sales.user_id', $register->user_id)
            ->where('sales.warehouse_id', $register->warehouse_id)
            ->whereBetween('sales.created_at', [$from, $to])
            ->groupBy('payment_sales.payment_method_id', 'payment_methods.name')
            ->select(
                'payment_sales.payment_method_id',
                DB::raw("COALESCE(payment_methods.name, 'Unknown') as name"),
                DB::raw('SUM(payment_sales.montant - COALESCE(payment_sales.change, 0)) as total')
            )
            ->get();

        $configuredIds = $methods->pluck('id')->map(fn ($id) => (string) $id)->all();
        $salesByMethod = $methods->map(function ($method) use ($paymentRows) {
            $row = $paymentRows->firstWhere('payment_method_id', $method->id);
            $category = $this->paymentMethodCategory($method->name);

            return [
                'id' => $method->id,
                'name' => $method->name,
                'category' => $category,
                'total' => round((float) ($row->total ?? 0), 2),
            ];
        })->values()->all();

        foreach ($paymentRows as $row) {
            if ($row->payment_method_id !== null && in_array((string) $row->payment_method_id, $configuredIds, true)) {
                continue;
            }
            $name = $row->name ?: 'Unknown';
            $salesByMethod[] = [
                'id' => $row->payment_method_id,
                'name' => $name,
                'category' => $this->paymentMethodCategory($name),
                'total' => round((float) $row->total, 2),
            ];
        }

        $cashMethodIds = collect($salesByMethod)
            ->where('category', 'cash')
            ->pluck('id')
            ->filter(fn ($id) => $id !== null)
            ->values()
            ->all();

        $cashRefunds = 0;
        if (! empty($cashMethodIds)) {
            $cashRefunds = PaymentSaleReturns::query()
                ->join('sale_returns', 'payment_sale_returns.sale_return_id', '=', 'sale_returns.id')
                ->whereNull('payment_sale_returns.deleted_at')
                ->whereNull('sale_returns.deleted_at')
                ->where('sale_returns.user_id', $register->user_id)
                ->where('sale_returns.warehouse_id', $register->warehouse_id)
                ->whereIn('payment_sale_returns.payment_method_id', $cashMethodIds)
                ->whereBetween('sale_returns.created_at', [$from, $to])
                ->sum(DB::raw('payment_sale_returns.montant - COALESCE(payment_sale_returns.change, 0)'));
        }

        $totalSales = Sale::whereNull('deleted_at')
            ->where('is_pos', 1)
            ->where('user_id', $register->user_id)
            ->where('warehouse_id', $register->warehouse_id)
            ->whereBetween('created_at', [$from, $to])
            ->sum('GrandTotal');
        $transactionCount = Sale::whereNull('deleted_at')
            ->where('is_pos', 1)
            ->where('user_id', $register->user_id)
            ->where('warehouse_id', $register->warehouse_id)
            ->whereBetween('created_at', [$from, $to])
            ->count();
        $cashSales = collect($salesByMethod)->where('category', 'cash')->sum('total');
        $cardTotal = collect($salesByMethod)->where('category', 'card')->sum('total');
        $transferTotal = collect($salesByMethod)->where('category', 'transfer')->sum('total');
        $storeCreditApplied = 0;
        if (Schema::hasTable('store_credit_voucher_transactions')) {
            $storeCreditApplied = StoreCreditVoucherTransaction::query()
                ->join('sales', 'store_credit_voucher_transactions.sale_id', '=', 'sales.id')
                ->where('store_credit_voucher_transactions.type', 'redeem')
                ->whereNull('sales.deleted_at')
                ->where('sales.is_pos', 1)
                ->where('sales.user_id', $register->user_id)
                ->where('sales.warehouse_id', $register->warehouse_id)
                ->whereBetween('sales.created_at', [$from, $to])
                ->sum('store_credit_voucher_transactions.amount');
        } else {
            Log::warning('Cash register closing summary skipped store credit totals because tenant schema is missing store_credit_voucher_transactions.', [
                'tenant_id' => function_exists('tenant') && tenant() ? (string) tenant()->id : null,
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
            'warehouse' => optional($register->warehouse)->name,
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

    protected function paymentMethodCategory(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));

        if (str_contains($normalized, 'cash') || str_contains($normalized, 'efectivo')) {
            return 'cash';
        }
        if (str_contains($normalized, 'card') || str_contains($normalized, 'tarjeta') || str_contains($normalized, 'credit') || str_contains($normalized, 'debit') || str_contains($normalized, 'tpe')) {
            return 'card';
        }
        if (str_contains($normalized, 'transfer') || str_contains($normalized, 'bank') || str_contains($normalized, 'banco')) {
            return 'transfer';
        }
        if (str_contains($normalized, 'check') || str_contains($normalized, 'cheque')) {
            return 'check';
        }

        return 'other';
    }

    protected function closingAuditStatus(float $difference): string
    {
        if (abs($difference) < 0.005) {
            return 'balanced';
        }

        return $difference > 0 ? 'over' : 'short';
    }

    protected function closingAuditStatusLabel(?string $status): string
    {
        return match ($status) {
            'balanced' => 'Cuadrada',
            'over' => 'Sobrante',
            'short' => 'Faltante',
            default => 'Sin estado',
        };
    }

    protected function buildSessionIdentitySnapshot(CashRegister $register, Carbon $closedAt, $closedByUser): array
    {
        $openedAt = $register->opened_at ? $register->opened_at->copy() : null;
        $openedByName = $this->userDisplayName($register->user);
        $closedByName = $this->userDisplayName($closedByUser);
        $warehouseName = optional($register->warehouse)->name;
        $durationSeconds = $openedAt ? $openedAt->diffInSeconds($closedAt) : null;

        return [
            'register_id' => $register->id,
            'register_number' => 'Register #'.$register->id,
            'opened_by_user_id' => $register->user_id,
            'opened_by_user_name' => $openedByName,
            'closed_by_user_id' => optional($closedByUser)->id,
            'closed_by_user_name' => $closedByName,
            'cashier_name' => $openedByName,
            'warehouse_id' => $register->warehouse_id,
            'warehouse_name' => $warehouseName,
            'tenant_id' => function_exists('tenant') && tenant() ? (string) tenant()->id : null,
            'opened_at' => $openedAt ? $openedAt->format('Y-m-d H:i:s') : null,
            'opened_date' => $openedAt ? $openedAt->toDateString() : null,
            'opened_time' => $openedAt ? $openedAt->format('H:i:s') : null,
            'closed_at' => $closedAt->format('Y-m-d H:i:s'),
            'closed_date' => $closedAt->toDateString(),
            'closed_time' => $closedAt->format('H:i:s'),
            'session_duration_seconds' => $durationSeconds,
            'session_duration_human' => $this->formatDuration($durationSeconds),
            'register_state' => 'closed',
        ];
    }

    protected function userDisplayName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $full = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

        return $full !== '' ? $full : ($user->username ?? $user->name ?? ('User #'.$user->id));
    }

    protected function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return $hours.' h '.$minutes.' min';
        }

        return $minutes.' min';
    }

    protected function cashDenominations(): array
    {
        $setting = Setting::first();
        $country = strtoupper((string) ($setting->country_code ?? (function_exists('tenant') && tenant() ? tenant()->country_code : 'HN')));

        if ($country === 'HN') {
            return [
                'currency_code' => 'HNL',
                'currency_symbol' => 'L.',
                'bills' => [500, 200, 100, 50, 20, 10, 5, 2, 1],
                'coins' => [0.5, 0.2, 0.1, 0.05],
            ];
        }

        return [
            'currency_code' => optional(optional($setting)->Currency)->code ?? optional($setting)->currency_code ?? 'USD',
            'currency_symbol' => optional(optional($setting)->Currency)->symbol ?? optional($setting)->currency_code ?? '$',
            'bills' => [100, 50, 20, 10, 5, 1],
            'coins' => [0.25, 0.1, 0.05, 0.01],
        ];
    }

    public function report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'cash_register_report', Sale::class);

        // Normalize date filter to Y-m-d (avoid timezone/invalid input issues)
        $today = Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->toDateString()
            : $today->copy()->subDays(6)->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->toDateString()
            : $today->toDateString();
        if ($from > $to) {
            $from = $to;
        }

        // Pagination + Sorting (align with Report_Sales)
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'opened_at';
        $dir = $request->SortType ?: 'desc';

        $allowedSorts = ['id', 'opened_at', 'closed_at', 'opening_balance', 'closing_balance', 'cash_in', 'cash_out', 'total_sales', 'difference', 'expected_cash', 'counted_cash', 'cash_difference', 'closing_status', 'status', 'warehouse_id', 'user_id'];
        if (! in_array($order, $allowedSorts, true)) {
            $order = 'opened_at';
        }

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        $query = CashRegister::with(['user:id,firstname,lastname,username', 'warehouse:id,name'])
            ->where(function ($q) use ($view_records) {
                if (! $view_records) {
                    return $q->where('user_id', Auth::user()->id);
                }
            })
            ->where('status', 'closed');
        if (! $is_all_warehouses) {
            $query->whereIn('warehouse_id', $warehouse_ids);
        }

        if ($request->filled('register_id')) {
            $query->where('id', $request->register_id);
        }
        if ($request->filled('user_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('user_id', $request->user_id)
                    ->orWhere('opened_by_user_id_snapshot', $request->user_id)
                    ->orWhere('closed_by_user_id', $request->user_id);
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('warehouse_id', $request->warehouse_id)
                    ->orWhere('warehouse_id_snapshot', $request->warehouse_id);
            });
        }
        if ($request->filled('closing_status')) {
            $query->where('closing_status', $request->closing_status);
        }
        $query->whereDate('closed_at', '>=', $from);
        $query->whereDate('closed_at', '<=', $to);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%$search%")
                    ->orWhere('register_number_snapshot', 'like', "%$search%")
                    ->orWhere('opened_by_user_name_snapshot', 'like', "%$search%")
                    ->orWhere('closed_by_user_name_snapshot', 'like', "%$search%")
                    ->orWhere('warehouse_name_snapshot', 'like', "%$search%")
                    ->orWhere('closing_status', 'like', "%$search%")
                    ->orWhere('opening_balance', 'like', "%$search%")
                    ->orWhere('closing_balance', 'like', "%$search%")
                    ->orWhere('cash_in', 'like', "%$search%")
                    ->orWhere('cash_out', 'like', "%$search%")
                    ->orWhere('total_sales', 'like', "%$search%")
                    ->orWhere('difference', 'like', "%$search%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('firstname', 'like', "%$search%")
                            ->orWhere('lastname', 'like', "%$search%")
                            ->orWhere('username', 'like', "%$search%");
                    })
                    ->orWhereHas('warehouse', function ($wq) use ($search) {
                        $wq->where('name', 'like', "%$search%");
                    });
            });
        }

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $items = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($items as $r) {
            $item['id'] = $r->id;
            $item['user_id'] = $r->user_id;
            $item['warehouse_id'] = $r->warehouse_id;
            $item['register_number'] = $r->register_number_snapshot ?: 'Register #'.$r->id;
            $item['cashier_firstname'] = null;
            $item['cashier_lastname'] = null;
            $item['cashier_username'] = null;
            $item['opened_by_user_id'] = $r->opened_by_user_id_snapshot ?: $r->user_id;
            $item['opened_by_user_name'] = $r->opened_by_user_name_snapshot ?: $this->userDisplayName($r->user);
            $item['closed_by_user_id'] = $r->closed_by_user_id;
            $item['closed_by_user_name'] = $r->closed_by_user_name_snapshot;
            $item['cashier_name'] = $item['opened_by_user_name'];
            $item['warehouse_snapshot_id'] = $r->warehouse_id_snapshot ?: $r->warehouse_id;
            $item['warehouse_name'] = $r->warehouse_name_snapshot ?: optional($r->warehouse)->name;
            $item['tenant_id'] = $r->tenant_id_snapshot;
            $item['opened_at'] = optional($r->opened_at)->format('Y-m-d H:i:s');
            $item['closed_at'] = $r->closed_at ? optional($r->closed_at)->format('Y-m-d H:i:s') : null;
            $item['opened_date'] = $r->opened_date_snapshot;
            $item['opened_time'] = $r->opened_time_snapshot;
            $item['closed_date'] = $r->closed_date_snapshot;
            $item['closed_time'] = $r->closed_time_snapshot;
            $item['session_duration_seconds'] = $r->session_duration_seconds;
            $item['session_duration_human'] = $this->formatDuration($r->session_duration_seconds);
            $item['status'] = $r->status;
            $item['closing_status'] = $r->closing_status ?: $this->closingAuditStatus((float) ($r->cash_difference ?? $r->difference ?? 0));
            $item['closing_status_label'] = $this->closingAuditStatusLabel($item['closing_status']);
            $item['opening_balance'] = number_format((float) $r->opening_balance, 2, '.', '');
            $item['cash_in'] = number_format((float) $r->cash_in, 2, '.', '');
            $item['cash_out'] = number_format((float) $r->cash_out, 2, '.', '');
            $item['total_sales'] = number_format((float) $r->total_sales, 2, '.', '');
            $item['closing_balance'] = is_null($r->closing_balance) ? null : number_format((float) $r->closing_balance, 2, '.', '');
            $item['sales_by_payment_method'] = $r->sales_by_payment_method ?: [];
            $item['cash_sales'] = number_format((float) $this->paymentCategoryTotal($item['sales_by_payment_method'], 'cash'), 2, '.', '');
            $item['card_sales'] = number_format((float) ($r->card_system_total ?? $this->paymentCategoryTotal($item['sales_by_payment_method'], 'card')), 2, '.', '');
            $item['transfer_sales'] = number_format((float) ($r->transfer_total ?? $this->paymentCategoryTotal($item['sales_by_payment_method'], 'transfer')), 2, '.', '');
            $item['other_sales'] = number_format((float) $this->paymentCategoryTotal($item['sales_by_payment_method'], 'other', true), 2, '.', '');
            $item['store_credit_applied'] = number_format((float) $this->paymentCategoryTotal($item['sales_by_payment_method'], 'store_credit'), 2, '.', '');
            $item['expected_cash'] = is_null($r->expected_cash) ? null : number_format((float) $r->expected_cash, 2, '.', '');
            $item['counted_cash'] = is_null($r->counted_cash) ? null : number_format((float) $r->counted_cash, 2, '.', '');
            $item['difference'] = is_null($r->cash_difference ?? $r->difference) ? null : number_format((float) ($r->cash_difference ?? $r->difference), 2, '.', '');
            $item['counted_denominations'] = $r->counted_denominations ?: [];
            $item['card_system_total'] = number_format((float) ($r->card_system_total ?? 0), 2, '.', '');
            $item['card_terminal_total'] = is_null($r->card_terminal_total) ? null : number_format((float) $r->card_terminal_total, 2, '.', '');
            $item['card_difference'] = is_null($r->card_difference) ? null : number_format((float) $r->card_difference, 2, '.', '');
            $item['card_batch_number'] = $r->card_batch_number;
            $item['card_reference'] = $r->card_reference;
            $item['card_notes'] = $r->card_notes;
            $item['transfer_total'] = number_format((float) ($r->transfer_total ?? 0), 2, '.', '');
            $item['transfers_verified'] = (bool) $r->transfers_verified;
            $item['transfer_notes'] = $r->transfer_notes;
            $item['cash_withdrawn_at_close'] = is_null($r->cash_withdrawn_at_close) ? null : number_format((float) $r->cash_withdrawn_at_close, 2, '.', '');
            $item['next_opening_float'] = is_null($r->next_opening_float) ? null : number_format((float) $r->next_opening_float, 2, '.', '');
            $item['notes'] = $r->notes;
            $item['closing_snapshot'] = $r->closing_snapshot ?: [];
            $data[] = $item;
        }

        // Users & Warehouses for filters (mirror sales report)
        $users = User::where('deleted_at', '=', null)->get(['id', 'username', 'firstname', 'lastname']);

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'totalRows' => $totalRows,
            'registers' => $data,
            'users' => $users,
            'warehouses' => $warehouses,
        ]);
    }

    protected function paymentCategoryTotal(array $methods, string $category, bool $includeChecks = false): float
    {
        $sum = 0;
        foreach ($methods as $method) {
            $methodCategory = $method['category'] ?? $this->paymentMethodCategory($method['name'] ?? '');
            $isOther = ! in_array($methodCategory, ['cash', 'card', 'transfer', 'store_credit'], true);
            if ($methodCategory === $category || ($includeChecks && $isOther)) {
                $sum += (float) ($method['total'] ?? 0);
            }
        }

        return $sum;
    }
}
