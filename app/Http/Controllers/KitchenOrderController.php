<?php

namespace App\Http\Controllers;

use App\Models\KitchenOrder;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Unit;
use App\Models\Warehouse;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KitchenOrderController extends Controller
{
    /**
     * GET /kitchen/orders
     * Query: status, from, to, q (ref/customer). Returns tickets grouped by status.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $statusFilter = trim((string) $request->query('status', ''));

        $orders = KitchenOrder::query()
            ->with('client', 'assignedUser', 'dispatchedWarehouse')
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('ref', 'like', "%{$q}%")
                        ->orWhereHas('client', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                                ->orWhere('phone', 'like', "%{$q}%");
                        });
                });
            })
            ->when($statusFilter !== '' && in_array($statusFilter, KitchenOrder::STATUSES, true),
                fn ($qq) => $qq->where('status', $statusFilter))
            ->when($from, fn ($qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('created_at', '<=', $to))
            ->orderBy('created_at', 'asc')
            ->get();

        $data = $orders->map(fn (KitchenOrder $o) => $this->mapOrder($o, true));

        // Group by the four kitchen statuses for the board layout.
        $grouped = [];
        foreach (KitchenOrder::STATUSES as $s) {
            $grouped[$s] = $data->where('status', $s)->values();
        }

        return response()->json([
            'data' => $data->values(),
            'grouped' => $grouped,
            'counts' => collect(KitchenOrder::STATUSES)
                ->mapWithKeys(fn ($s) => [$s => $data->where('status', $s)->count()]),
            // Bundled so the board's "send to warehouse" dropdown is always populated.
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET /kitchen/orders/poll
     * Lightweight signal so the board can detect new/updated tickets without re-rendering.
     */
    public function poll(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $latest = KitchenOrder::max('id');
        $lastUpdate = KitchenOrder::max('updated_at');

        $counts = collect(KitchenOrder::STATUSES)->mapWithKeys(function ($s) {
            return [$s => KitchenOrder::where('status', $s)->count()];
        });

        return response()->json([
            'latest_id' => (int) $latest,
            'last_update' => $lastUpdate ? Carbon::parse($lastUpdate)->toDateTimeString() : null,
            'counts' => $counts,
        ]);
    }

    /**
     * GET /kitchen/orders/{id}
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $order = KitchenOrder::with('client', 'warehouse', 'assignedUser', 'sentBy', 'dispatchedWarehouse')->findOrFail($id);

        return response()->json($this->mapOrder($order, true));
    }

    /**
     * POST /kitchen/orders   (Send Later)
     * Create a kitchen ticket for an existing sale that does not already have one.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'instructions' => 'nullable|string',
        ]);

        $existing = KitchenOrder::where('sale_id', $data['sale_id'])->first();
        if ($existing) {
            return response()->json([
                'ok' => true,
                'already_sent' => true,
                'order' => $this->mapOrder($existing->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
            ]);
        }

        $order = self::createForSale((int) $data['sale_id'], $data['instructions'] ?? null);

        if (! $order) {
            return response()->json(['error' => 'Sale not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ], 201);
    }

    /**
     * PATCH /kitchen/orders/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'status' => 'required|in:pending,preparing,completed,on_hold',
        ]);

        $order = KitchenOrder::findOrFail($id);
        $order->status = $data['status'];

        if ($data['status'] === 'preparing' && ! $order->started_at) {
            $order->started_at = now();
        }
        if ($data['status'] === 'completed') {
            $order->completed_at = now();
        }

        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * PATCH /kitchen/orders/{id}/assign
     */
    public function assign(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $order = KitchenOrder::findOrFail($id);
        $order->assigned_to = $data['assigned_to'] ?? null;
        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * GET /kitchen/warehouses
     * Lightweight warehouse list for the "send to warehouse" dropdown. Gated by the kitchen
     * view permission so kitchen staff don't need the separate warehouse permission.
     */
    public function warehouses(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', KitchenOrder::class);

        $warehouses = Warehouse::whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['warehouses' => $warehouses]);
    }

    /**
     * PATCH /kitchen/orders/{id}/dispatch
     * Records which warehouse a completed order was handed off to. Display-only —
     * no stock movement or transfer is created.
     */
    public function dispatchOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manage', KitchenOrder::class);

        $data = $request->validate([
            'dispatched_warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $order = KitchenOrder::findOrFail($id);

        if ($order->status !== 'completed') {
            return response()->json(['error' => 'Only completed orders can be sent to a warehouse.'], 422);
        }

        $order->dispatched_warehouse_id = (int) $data['dispatched_warehouse_id'];
        $order->dispatched_at = now();
        $order->save();

        return response()->json([
            'ok' => true,
            'order' => $this->mapOrder($order->load('client', 'assignedUser', 'dispatchedWarehouse'), true),
        ]);
    }

    /**
     * Create a kitchen ticket from a sale. Shared by the POS "Send to Kitchen" flow
     * (PosController) and the "Send Later" endpoint. Returns null if the sale is missing.
     */
    public static function createForSale(int $saleId, ?string $instructions = null): ?KitchenOrder
    {
        $sale = Sale::find($saleId);
        if (! $sale) {
            return null;
        }

        return KitchenOrder::create([
            'sale_id' => $sale->id,
            'ref' => $sale->Ref,
            'client_id' => $sale->client_id,
            'warehouse_id' => $sale->warehouse_id,
            'user_id' => optional(Auth::user())->id,
            'status' => 'pending',
            'instructions' => $instructions !== null && $instructions !== '' ? $instructions : $sale->notes,
            'sent_at' => now(),
        ]);
    }

    /**
     * Shape a kitchen order for the frontend.
     */
    protected function mapOrder(KitchenOrder $o, bool $withItems = false): array
    {
        $payload = [
            'id' => $o->id,
            'sale_id' => $o->sale_id,
            'ref' => $o->ref,
            'status' => $o->status,
            'customer_name' => optional($o->client)->name,
            'assigned_to' => $o->assigned_to,
            'assigned_name' => optional($o->assignedUser)->firstname
                ? trim(optional($o->assignedUser)->firstname.' '.optional($o->assignedUser)->lastname)
                : null,
            'instructions' => $o->instructions,
            'sent_at' => optional($o->sent_at)->toDateTimeString(),
            'started_at' => optional($o->started_at)->toDateTimeString(),
            'completed_at' => optional($o->completed_at)->toDateTimeString(),
            'created_at' => optional($o->created_at)->toDateTimeString(),
            // Display-only dispatch marker
            'dispatched_warehouse_id' => $o->dispatched_warehouse_id,
            'dispatched_warehouse_name' => optional($o->dispatchedWarehouse)->name,
            'dispatched_at' => optional($o->dispatched_at)->toDateTimeString(),
        ];

        if ($withItems) {
            $payload['items'] = $this->itemsForSale($o->sale_id);
        }

        return $payload;
    }

    /**
     * Read the line items for a sale from sale_details, resolving product / variant / unit names.
     */
    protected function itemsForSale($saleId): array
    {
        $details = SaleDetail::with('product')
            ->where('sale_id', $saleId)
            ->get();

        $unitCache = [];
        $variantCache = [];

        return $details->map(function (SaleDetail $d) use (&$unitCache, &$variantCache) {
            $name = optional($d->product)->name ?? ('#'.$d->product_id);

            if ($d->product_variant_id) {
                if (! array_key_exists($d->product_variant_id, $variantCache)) {
                    $variantCache[$d->product_variant_id] = ProductVariant::find($d->product_variant_id);
                }
                $variant = $variantCache[$d->product_variant_id];
                if ($variant) {
                    $name .= ' - '.$variant->name;
                }
            }

            $unitName = null;
            if ($d->sale_unit_id) {
                if (! array_key_exists($d->sale_unit_id, $unitCache)) {
                    $unitCache[$d->sale_unit_id] = Unit::find($d->sale_unit_id);
                }
                $unit = $unitCache[$d->sale_unit_id];
                $unitName = $unit ? ($unit->ShortName ?: $unit->name) : null;
            }

            return [
                'id' => $d->id,
                'product_id' => $d->product_id,
                'name' => $name,
                'quantity' => (float) $d->quantity,
                'unit' => $unitName,
                'price' => (float) $d->price,
                'total' => (float) $d->total,
            ];
        })->values()->all();
    }
}
