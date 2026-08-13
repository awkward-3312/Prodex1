<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Warehouse;
use App\Services\PromotionEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionsController extends Controller
{
    /**
     * Paginated list with optional filters: warehouse_id, kind, active, search.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $perPage  = $request->input('limit', 10);
        $page     = (int) $request->input('page', 1);
        $order    = $request->input('SortField', 'id');
        $dir      = $request->input('SortType', 'desc');
        $search   = $request->input('search');
        $whFilter = $request->input('warehouse_id');
        $kind     = $request->input('kind');
        $active   = $request->input('active');

        $allowedSorts = ['id', 'name', 'kind', 'discount_type', 'discount_value', 'is_active', 'priority', 'starts_at', 'ends_at'];
        if (! in_array($order, $allowedSorts, true)) {
            $order = 'id';
        }
        if (! in_array(strtolower($dir), ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $query = Promotion::query()
            ->with(['warehouses:id,name', 'products:id,name,code'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('name', 'LIKE', "%{$search}%")
                       ->orWhere('code', 'LIKE', "%{$search}%")
                       ->orWhere('description', 'LIKE', "%{$search}%");
                });
            })
            ->when($whFilter, fn ($q) => $q->whereHas('warehouses', fn ($qq) => $qq->where('warehouses.id', (int) $whFilter)))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($active !== null && $active !== '', fn ($q) => $q->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN)));

        $totalRows = (clone $query)->count();
        if ((string) $perPage === '-1') {
            $perPage = max($totalRows, 1);
        }

        $promotions = $query->orderBy($order, $dir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'promotions' => $promotions,
            'totalRows'  => $totalRows,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $promotion = Promotion::with(['warehouses:id,name', 'products:id,name,code'])->findOrFail($id);

        return response()->json(['promotion' => $promotion]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Promotion::class);

        $data = $this->validateInput($request);

        $promotion = \DB::transaction(function () use ($data) {
            $promotion = Promotion::create([
                'name'              => $data['name'],
                'code'              => $data['code'] ?? null,
                'description'       => $data['description'] ?? null,
                'kind'              => $data['kind'],
                'discount_type'     => $data['discount_type'],
                'discount_value'    => $data['discount_value'],
                'is_active'         => $data['is_active'] ?? true,
                'starts_at'         => $data['starts_at'] ?? null,
                'ends_at'           => $data['ends_at'] ?? null,
                'time_of_day_start' => $data['time_of_day_start'] ?? null,
                'time_of_day_end'   => $data['time_of_day_end'] ?? null,
                'min_cart_total'    => $data['min_cart_total'] ?? null,
                'min_item_count'    => $data['min_item_count'] ?? null,
                'product_scope'     => $data['product_scope'] ?? 'all',
                'priority'          => $data['priority'] ?? 0,
                'stackable'         => $data['stackable'] ?? false,
                'usage_limit_total' => $data['usage_limit_total'] ?? null,
                'usage_limit_per_customer' => $data['usage_limit_per_customer'] ?? null,
            ]);

            if (! empty($data['warehouse_ids'])) {
                $promotion->warehouses()->sync($data['warehouse_ids']);
            }
            if (($data['product_scope'] ?? 'all') === 'specific' && ! empty($data['product_ids'])) {
                $promotion->products()->sync($data['product_ids']);
            }

            return $promotion;
        });

        return response()->json([
            'success'   => true,
            'promotion' => $promotion->load(['warehouses:id,name', 'products:id,name,code']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Promotion::class);

        $promotion = Promotion::findOrFail($id);
        $data = $this->validateInput($request);

        \DB::transaction(function () use ($promotion, $data) {
            $promotion->update([
                'name'              => $data['name'],
                'code'              => $data['code'] ?? null,
                'description'       => $data['description'] ?? null,
                'kind'              => $data['kind'],
                'discount_type'     => $data['discount_type'],
                'discount_value'    => $data['discount_value'],
                'is_active'         => $data['is_active'] ?? $promotion->is_active,
                'starts_at'         => $data['starts_at'] ?? null,
                'ends_at'           => $data['ends_at'] ?? null,
                'time_of_day_start' => $data['time_of_day_start'] ?? null,
                'time_of_day_end'   => $data['time_of_day_end'] ?? null,
                'min_cart_total'    => $data['min_cart_total'] ?? null,
                'min_item_count'    => $data['min_item_count'] ?? null,
                'product_scope'     => $data['product_scope'] ?? 'all',
                'priority'          => $data['priority'] ?? 0,
                'stackable'         => $data['stackable'] ?? false,
                'usage_limit_total' => $data['usage_limit_total'] ?? null,
                'usage_limit_per_customer' => $data['usage_limit_per_customer'] ?? null,
            ]);

            $promotion->warehouses()->sync($data['warehouse_ids'] ?? []);

            if (($data['product_scope'] ?? 'all') === 'specific') {
                $promotion->products()->sync($data['product_ids'] ?? []);
            } else {
                $promotion->products()->sync([]);
            }
        });

        return response()->json([
            'success'   => true,
            'promotion' => $promotion->load(['warehouses:id,name', 'products:id,name,code']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Promotion::class);

        $promotion = Promotion::findOrFail($id);
        $promotion->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Flip is_active without touching anything else.
     */
    public function toggle(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Promotion::class);

        $promotion = Promotion::findOrFail($id);
        $promotion->is_active = ! $promotion->is_active;
        $promotion->save();

        return response()->json([
            'success'   => true,
            'is_active' => $promotion->is_active,
        ]);
    }

    /**
     * Return the warehouse names that a candidate promotion would apply to, given
     * an array of warehouse IDs. Used by the create form for "Preview which
     * warehouses a promotion applies to before saving".
     */
    public function previewWarehouses(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $ids = array_map('intval', (array) $request->input('warehouse_ids', []));
        $warehouses = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['warehouses' => $warehouses]);
    }

    /**
     * Resolve which promotions apply to a given cart context. Used by POS to
     * show eligible promotions inline before checkout.
     *
     * Request payload: warehouse_id (required), subtotal, item_count, product_ids[],
     * code (optional, unlocks code-gated promotions), client_id (optional, for
     * per-customer cap enforcement).
     */
    public function applicable(Request $request, PromotionEngine $engine)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $this->validate($request, [
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'subtotal'     => 'nullable|numeric|min:0',
            'item_count'   => 'nullable|integer|min:0',
            'product_ids'  => 'nullable|array',
            'product_ids.*'=> 'integer',
            'product_subtotals' => 'nullable|array',
            'product_subtotals.*.product_id' => 'required_with:product_subtotals|integer',
            'product_subtotals.*.subtotal'   => 'required_with:product_subtotals|numeric|min:0',
            'code'         => 'nullable|string|max:64',
            'client_id'    => 'nullable|integer',
        ]);

        $result = $engine->applyTo([
            'warehouse_id' => (int) $request->warehouse_id,
            'subtotal'     => (float) $request->input('subtotal', 0),
            'item_count'   => (int) $request->input('item_count', 0),
            'product_ids'  => (array) $request->input('product_ids', []),
            'product_subtotals' => (array) $request->input('product_subtotals', []),
            'code'         => $request->input('code'),
            'client_id'    => $request->input('client_id'),
            'now'          => Carbon::now(),
        ]);

        return response()->json($result);
    }

    /**
     * Validate a single promotion code in the context of a cart, returning a
     * structured ok/reason so the POS can surface a precise message to the
     * cashier (expired, exhausted, not valid here, etc.).
     */
    public function validateCode(Request $request, PromotionEngine $engine)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $this->validate($request, [
            'code'         => 'required|string|max:64',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'client_id'    => 'nullable|integer',
        ]);

        $result = $engine->lookupCode((string) $request->code, [
            'warehouse_id' => (int) $request->warehouse_id,
            'client_id'    => $request->input('client_id'),
            'now'          => Carbon::now(),
        ]);

        return response()->json([
            'ok' => $result['ok'],
            'reason' => $result['reason'],
            'promotion' => $result['promotion'] ? [
                'id' => (int) $result['promotion']->id,
                'name' => $result['promotion']->name,
                'kind' => $result['promotion']->kind,
                'discount_type' => $result['promotion']->discount_type,
                'discount_value' => (float) $result['promotion']->discount_value,
            ] : null,
        ]);
    }

    /**
     * Paginated promotion-usage list, scoped by optional filters:
     *   - promotion_id, warehouse_id, client_id
     *   - from / to (used_at date range, inclusive)
     *   - search (matches promotion name or code, or sale Ref via joined query)
     *
     * Each row is enriched with related promotion / sale / client / warehouse
     * labels so the frontend can render a self-contained table.
     */
    public function usages(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $perPage  = $request->input('limit', 15);
        $page     = (int) $request->input('page', 1);
        $order    = $request->input('SortField', 'used_at');
        $dir      = strtolower((string) $request->input('SortType', 'desc'));
        $search   = trim((string) $request->input('search', ''));
        $promoId  = $request->input('promotion_id');
        $whId     = $request->input('warehouse_id');
        $clientId = $request->input('client_id');
        $from     = $this->parseDate($request->input('from'));
        $to       = $this->parseDate($request->input('to'), true);

        $allowedSorts = ['id', 'used_at', 'discount_amount', 'promotion_id', 'sale_id', 'client_id', 'warehouse_id'];
        if (! in_array($order, $allowedSorts, true)) {
            $order = 'used_at';
        }
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $query = PromotionUsage::query()
            ->with([
                'promotion:id,name,code,kind,discount_type,discount_value',
                'sale:id,Ref',
                'client:id,name',
            ])
            ->when($promoId, fn ($q) => $q->where('promotion_id', (int) $promoId))
            ->when($whId, fn ($q) => $q->where('warehouse_id', (int) $whId))
            ->when($clientId, fn ($q) => $q->where('client_id', (int) $clientId))
            ->when($from, fn ($q) => $q->where('used_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('used_at', '<=', $to))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('code', 'LIKE', "%{$search}%")
                       ->orWhereHas('promotion', fn ($p) => $p->where('name', 'LIKE', "%{$search}%")->orWhere('code', 'LIKE', "%{$search}%"))
                       ->orWhereHas('sale', fn ($s) => $s->where('Ref', 'LIKE', "%{$search}%"))
                       ->orWhereHas('client', fn ($c) => $c->where('name', 'LIKE', "%{$search}%"));
                });
            });

        $totalRows = (clone $query)->count();
        if ((string) $perPage === '-1') {
            $perPage = max($totalRows, 1);
        }

        $usages = $query->orderBy($order, $dir)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // Map warehouse_id → name once so we don't run N+1 lookups for a column
        // that's just a label. Warehouses are typically a small set.
        $warehouseIds = $usages->pluck('warehouse_id')->filter()->unique()->all();
        $warehouseNames = $warehouseIds
            ? Warehouse::whereIn('id', $warehouseIds)->pluck('name', 'id')->toArray()
            : [];

        $rows = $usages->map(function ($u) use ($warehouseNames) {
            return [
                'id'              => (int) $u->id,
                'used_at'         => optional($u->used_at)->format('Y-m-d H:i:s'),
                'discount_amount' => (float) $u->discount_amount,
                'code'            => $u->code,
                'promotion'       => $u->promotion ? [
                    'id' => (int) $u->promotion->id,
                    'name' => $u->promotion->name,
                    'kind' => $u->promotion->kind,
                    'code' => $u->promotion->code,
                    'discount_type' => $u->promotion->discount_type,
                    'discount_value' => (float) $u->promotion->discount_value,
                ] : null,
                'sale'            => $u->sale ? ['id' => (int) $u->sale->id, 'Ref' => $u->sale->Ref] : null,
                'client'          => $u->client ? ['id' => (int) $u->client->id, 'name' => $u->client->name] : null,
                'warehouse'       => $u->warehouse_id ? [
                    'id' => (int) $u->warehouse_id,
                    'name' => $warehouseNames[$u->warehouse_id] ?? null,
                ] : null,
            ];
        });

        return response()->json([
            'usages'    => $rows,
            'totalRows' => $totalRows,
        ]);
    }

    /**
     * Aggregate report: per-promotion counts + total discount within the same
     * filter window. Useful for "which promotion saved customers the most" and
     * for cap-progress dashboards.
     */
    public function usageSummary(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Promotion::class);

        $whId     = $request->input('warehouse_id');
        $clientId = $request->input('client_id');
        $from     = $this->parseDate($request->input('from'));
        $to       = $this->parseDate($request->input('to'), true);

        $summary = PromotionUsage::query()
            ->select(
                'promotion_id',
                DB::raw('COUNT(*) as uses'),
                DB::raw('SUM(discount_amount) as total_discount'),
                DB::raw('COUNT(DISTINCT client_id) as unique_customers')
            )
            ->when($whId, fn ($q) => $q->where('warehouse_id', (int) $whId))
            ->when($clientId, fn ($q) => $q->where('client_id', (int) $clientId))
            ->when($from, fn ($q) => $q->where('used_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('used_at', '<=', $to))
            ->groupBy('promotion_id')
            ->orderByDesc('total_discount')
            ->get();

        $promoIds = $summary->pluck('promotion_id')->all();
        $promotions = $promoIds
            ? Promotion::whereIn('id', $promoIds)->get(['id', 'name', 'code', 'kind', 'usage_limit_total'])->keyBy('id')
            : collect();

        $rows = $summary->map(function ($s) use ($promotions) {
            $p = $promotions->get($s->promotion_id);
            return [
                'promotion_id'    => (int) $s->promotion_id,
                'name'            => $p ? $p->name : '—',
                'code'            => $p ? $p->code : null,
                'kind'            => $p ? $p->kind : null,
                'uses'            => (int) $s->uses,
                'unique_customers'=> (int) $s->unique_customers,
                'total_discount'  => (float) $s->total_discount,
                'usage_limit_total' => $p && $p->usage_limit_total !== null ? (int) $p->usage_limit_total : null,
            ];
        });

        $totals = [
            'uses' => (int) $rows->sum('uses'),
            'total_discount' => (float) $rows->sum('total_discount'),
            'promotions_with_usage' => $rows->count(),
        ];

        return response()->json([
            'summary' => $rows,
            'totals' => $totals,
        ]);
    }

    private function parseDate($value, bool $endOfDay = false): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            $c = Carbon::parse($value);
            return $endOfDay ? $c->endOfDay()->format('Y-m-d H:i:s') : $c->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validateInput(Request $request): array
    {
        return $this->validate($request, [
            'name'             => 'required|string|max:192',
            'code'             => 'nullable|string|max:64',
            'description'      => 'nullable|string',
            'kind'             => 'required|in:discount,promotion',
            'discount_type'    => 'required|in:percentage,fixed',
            'discount_value'   => 'required|numeric|min:0',
            'is_active'        => 'nullable|boolean',
            'starts_at'        => 'nullable|date',
            'ends_at'          => 'nullable|date|after_or_equal:starts_at',
            'time_of_day_start'=> 'nullable|date_format:H:i:s',
            'time_of_day_end'  => 'nullable|date_format:H:i:s',
            'min_cart_total'   => 'nullable|numeric|min:0',
            'min_item_count'   => 'nullable|integer|min:0',
            'product_scope'    => 'nullable|in:all,specific',
            'priority'         => 'nullable|integer',
            'stackable'        => 'nullable|boolean',
            'usage_limit_total' => 'nullable|integer|min:0',
            'usage_limit_per_customer' => 'nullable|integer|min:0',
            'warehouse_ids'    => 'nullable|array',
            'warehouse_ids.*'  => 'integer|exists:warehouses,id',
            'product_ids'      => 'nullable|array',
            'product_ids.*'    => 'integer|exists:products,id',
        ]);
    }
}
