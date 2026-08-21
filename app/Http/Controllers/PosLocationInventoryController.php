<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Services\BatchLocationService;
use App\Services\InventoryLocationScopeService;
use App\Services\InventoryService;
use App\Services\SerialLocationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosLocationInventoryController extends BaseController
{
    public function show(Request $request, int $locationId, int $productId)
    {
        $location = $this->authorizedSellableLocation($request, $locationId);
        $product = Product::whereNull('deleted_at')->findOrFail($productId);
        $variantId = $request->filled('product_variant_id')
            ? (int) $request->input('product_variant_id')
            : null;

        $inventory = app(InventoryService::class);
        $payload = [
            'inventory_location' => $this->locationPayload($location),
            'product_id' => (int) $product->id,
            'product_variant_id' => $variantId,
            'quantity' => $inventory->quantity($locationId, $productId, $variantId),
            'reserved_quantity' => $inventory->reserved($locationId, $productId, $variantId),
            'available_quantity' => $inventory->available($locationId, $productId, $variantId),
            'is_batch_tracked' => (bool) ($product->is_batch_tracked ?? false),
            'is_serial_tracked' => (bool) ($product->is_imei ?? false),
            'batches' => [],
            'serials' => [],
        ];

        if ($payload['is_batch_tracked']) {
            $payload['batches'] = app(BatchLocationService::class)->availableBatches(
                $locationId,
                $productId,
                $variantId
            );
        }

        if ($payload['is_serial_tracked']) {
            $payload['serials'] = app(SerialLocationService::class)->availableSerials(
                $locationId,
                $productId,
                $variantId
            );
        }

        return response()->json($payload);
    }

    /**
     * Bulk stock snapshot used by the POS catalog.
     *
     * Product metadata/pricing still comes from the historical catalog endpoint
     * during the transition, but every quantity displayed to a location-aware
     * cashier is replaced with the stock from this physical inventory location.
     */
    public function stockMap(Request $request, int $locationId)
    {
        $location = $this->authorizedSellableLocation($request, $locationId);

        $rows = InventoryLocationStock::query()
            ->where('inventory_location_id', $location->id)
            ->get([
                'product_id',
                'product_variant_id',
                'quantity',
                'reserved_quantity',
                'updated_at',
            ]);

        return response()->json([
            'inventory_location' => $this->locationPayload($location),
            'products' => $this->formatRows($rows),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Incremental counterpart of stockMap(). It allows the POS to keep a large
     * catalog current without polling product_warehouse after the location cutover.
     */
    public function changes(Request $request, int $locationId)
    {
        $location = $this->authorizedSellableLocation($request, $locationId);
        $since = $request->input('since');

        if (! $since) {
            throw ValidationException::withMessages([
                'since' => 'Indique la fecha de la última sincronización de inventario.',
            ]);
        }

        try {
            $sinceAt = Carbon::parse($since);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'since' => 'La fecha de sincronización de inventario no es válida.',
            ]);
        }

        $rows = InventoryLocationStock::query()
            ->where('inventory_location_id', $location->id)
            ->where('updated_at', '>', $sinceAt)
            ->get([
                'product_id',
                'product_variant_id',
                'quantity',
                'reserved_quantity',
                'updated_at',
            ]);

        return response()->json([
            'inventory_location' => $this->locationPayload($location),
            'products' => $this->formatRows($rows),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function authorizedSellableLocation(Request $request, int $locationId): InventoryLocation
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        if (! app(InventoryLocationScopeService::class)->canAccess($user, $locationId)) {
            abort(403, 'No tiene acceso a esta ubicación de inventario.');
        }

        $location = InventoryLocation::active()
            ->where('is_sellable', true)
            ->find($locationId);

        if (! $location) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación no existe, está inactiva o no está habilitada para venta.',
            ]);
        }

        return $location;
    }

    private function locationPayload(InventoryLocation $location): array
    {
        return [
            'id' => (int) $location->id,
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'code' => (string) $location->code,
            'name' => (string) $location->name,
        ];
    }

    private function formatRows($rows): array
    {
        if ($rows->isEmpty()) return [];

        $productIds = $rows->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $products = Product::with('unitSale')
            ->whereIn('id', $productIds)
            ->get(['id', 'unit_sale_id'])
            ->keyBy('id');

        return $rows->map(function ($row) use ($products) {
            $quantity = round((float) $row->quantity, 3);
            $reserved = round((float) $row->reserved_quantity, 3);
            $available = round(max(0, $quantity - $reserved), 3);
            $product = $products->get((int) $row->product_id);
            $unit = $product?->unitSale;

            $saleQuantity = $available;
            if ($unit) {
                $operatorValue = (float) ($unit->operator_value ?: 1);
                if ($operatorValue <= 0) $operatorValue = 1;
                $saleQuantity = $unit->operator === '/'
                    ? $available * $operatorValue
                    : $available / $operatorValue;
            }

            return [
                'id' => (int) $row->product_id,
                'product_id' => (int) $row->product_id,
                'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                'qte' => $quantity,
                'reserved_quantity' => $reserved,
                'available_quantity' => $available,
                'qte_sale' => round($saleQuantity, 3),
                'updated_at' => optional($row->updated_at)->toIso8601String(),
            ];
        })->values()->all();
    }
}
