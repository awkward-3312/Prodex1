<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\BatchLocationService;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferBusinessDestinationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransferLocationController extends BaseController
{
    public function options(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Transfer::class);

        $sourceIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);
        $sources = InventoryLocation::with(['branch', 'warehouse'])
            ->active()
            ->whereIn('id', $sourceIds)
            ->orderBy('id')
            ->get();
        $fallbackWarehouseId = $this->fallbackWarehouseId();
        $destinationService = app(TransferBusinessDestinationService::class);

        $sourceRows = $sources
            ->map(fn ($location) => $this->optionRow($location, $fallbackWarehouseId))
            ->values();

        $destinationGroups = [];
        foreach ($sources as $source) {
            $destinationGroups[(string) $source->id] = $destinationService
                ->optionsForSource($source)
                ->map(function (array $row) use ($fallbackWarehouseId) {
                    $location = InventoryLocation::with(['branch', 'warehouse'])->active()->find($row['id']);
                    if (! $location) return null;
                    $option = $this->optionRow($location, $fallbackWarehouseId);
                    $option['name'] = $row['name'];
                    $option['destination_type'] = $row['destination_type'] ?? null;
                    return $option;
                })
                ->filter()
                ->values()
                ->all();
        }

        return response()->json([
            'sources' => $sourceRows,
            // The destination list is intentionally empty until an origin is chosen.
            // The UI then uses destination_groups[source_location_id].
            'destinations' => [],
            'destination_groups' => $destinationGroups,
            'business_routing' => true,
        ]);
    }

    public function context(Request $request, int $transferId)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Transfer::class);
        $transfer = Transfer::with(['fromInventoryLocation.branch', 'fromInventoryLocation.warehouse', 'toInventoryLocation.branch', 'toInventoryLocation.warehouse'])
            ->whereNull('deleted_at')->findOrFail($transferId);

        if (! $transfer->from_inventory_location_id || ! $transfer->to_inventory_location_id) {
            return response()->json(['location_aware' => false]);
        }

        $fallbackWarehouseId = $this->fallbackWarehouseId();
        return response()->json([
            'location_aware' => true,
            'from' => $this->optionRow($transfer->fromInventoryLocation, $fallbackWarehouseId),
            'to' => $this->optionRow($transfer->toInventoryLocation, $fallbackWarehouseId),
        ]);
    }

    public function products(Request $request, int $locationId)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Transfer::class);
        $location = $this->accessibleSource($user, $locationId);

        $stocks = InventoryLocationStock::where('inventory_location_id', $location->id)
            ->where('quantity', '>', 0)->get();
        if ($stocks->isEmpty()) return response()->json([]);

        $products = Product::with(['unitPurchase', 'variants'])
            ->whereNull('deleted_at')->whereIn('id', $stocks->pluck('product_id')->unique())
            ->get()->keyBy('id');
        $variants = ProductVariant::whereIn('id', $stocks->pluck('product_variant_id')->filter()->unique())
            ->get()->keyBy('id');

        $rows = [];
        foreach ($stocks as $stock) {
            $product = $products->get($stock->product_id);
            if (! $product || $product->type === 'is_service') continue;
            $variant = $stock->product_variant_id ? $variants->get($stock->product_variant_id) : null;
            $available = max(0, (float) $stock->quantity - (float) $stock->reserved_quantity);
            if ($available <= 0) continue;

            $unit = $product->unitPurchase;
            $code = $variant?->code ?: $product->code;
            $name = $variant ? '['.$variant->name.']'.$product->name : $product->name;
            $rows[] = [
                'id' => (int) $product->id,
                'product_id' => (int) $product->id,
                'product_variant_id' => $variant ? (int) $variant->id : null,
                'code' => (string) $code,
                'barcode' => (string) $code,
                'name' => (string) $name,
                'qte' => round($available, 3),
                'qte_purchase' => round($this->fromBaseQuantity($available, $unit), 3),
                'inventory_location_id' => (int) $location->id,
            ];
        }

        return response()->json($rows);
    }

    public function product(Request $request, int $locationId, int $productId)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Transfer::class);
        $location = $this->accessibleSource($user, $locationId);
        $variantId = $request->filled('product_variant_id') && (int) $request->product_variant_id > 0
            ? (int) $request->product_variant_id : null;

        $product = Product::with('unitPurchase')->whereNull('deleted_at')->findOrFail($productId);
        $variant = $variantId ? ProductVariant::where('product_id', $productId)->findOrFail($variantId) : null;
        $stock = InventoryLocationStock::where('inventory_location_id', $location->id)
            ->where('product_id', $productId)->where('variant_key', $variantId ?: 0)->first();

        $available = $stock ? max(0, (float) $stock->quantity - (float) $stock->reserved_quantity) : 0.0;
        $unit = $product->unitPurchase;
        $cost = (float) ($variant?->cost ?? $product->cost ?? 0);
        $discount = (float) ($product->discount ?? 0);
        $discountMethod = (string) ($product->discount_method ?? '2');
        $discountNet = $discountMethod === '1' ? $cost * $discount / 100 : $discount;
        $taxPercent = (float) ($product->TaxNet ?? 0);
        $taxMethod = (string) ($product->tax_method ?? '1');
        $taxCost = ($cost - $discountNet) * $taxPercent / 100;
        $netCost = $taxMethod === '1' ? $cost - $discountNet : $cost - $discountNet - $taxCost;

        return response()->json([
            'id' => (int) $product->id,
            'name' => $variant ? '['.$variant->name.']'.$product->name : $product->name,
            'discount' => $discount,
            'DiscountNet' => $discountNet,
            'discount_method' => $discountMethod,
            'Net_cost' => $netCost,
            'Unit_cost' => $cost,
            'tax_cost' => $taxCost,
            'tax_method' => $taxMethod,
            'tax_percent' => $taxPercent,
            'unitPurchase' => $unit?->ShortName ?? '',
            'fix_cost' => $cost,
            'purchase_unit_id' => $unit?->id,
            'is_batch_tracked' => (bool) ($product->is_batch_tracked ?? false),
            'warehouse_location' => ['code' => $location->code, 'name' => $location->name],
            'qte' => round($available, 3),
            'qte_purchase' => round($this->fromBaseQuantity($available, $unit), 3),
        ]);
    }

    public function batches(Request $request, int $locationId, int $productId, int $variantId = 0)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', Transfer::class);
        $this->accessibleSource($user, $locationId);

        return response()->json([
            'supported' => true,
            'batches' => collect(app(BatchLocationService::class)->availableBatches(
                $locationId, $productId, $variantId > 0 ? $variantId : null
            ))->map(fn ($batch) => [
                'id' => $batch['id'], 'batch_no' => $batch['batch_no'],
                'expiry_date' => $batch['expiry_date'], 'qty_available' => $batch['available_quantity'],
                'unit_cost' => $batch['unit_cost'],
            ])->values(),
        ]);
    }

    private function accessibleSource($user, int $locationId): InventoryLocation
    {
        if (! app(InventoryLocationScopeService::class)->canAccess($user, $locationId)) abort(403);
        $location = InventoryLocation::active()->find($locationId);
        if (! $location) throw ValidationException::withMessages(['inventory_location_id' => 'La ubicación no existe o está inactiva.']);
        return $location;
    }

    private function optionRow(InventoryLocation $location, int $fallbackWarehouseId): array
    {
        $owner = $location->branch_id ? optional($location->branch)->name : optional($location->warehouse)->name;
        return [
            'id' => (int) $location->id,
            'name' => trim(($owner ? $owner.' · ' : '').$location->name),
            'code' => (string) $location->code,
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'warehouse_id' => $location->warehouse_id ? (int) $location->warehouse_id : null,
            'legacy_warehouse_id' => $location->warehouse_id ? (int) $location->warehouse_id : $fallbackWarehouseId,
            'type' => $location->type,
            'is_sellable' => (bool) $location->is_sellable,
        ];
    }

    private function fallbackWarehouseId(): int
    {
        $setting = Setting::whereNull('deleted_at')->first();
        if ($setting?->warehouse_id && Warehouse::whereNull('deleted_at')->whereKey($setting->warehouse_id)->exists()) {
            return (int) $setting->warehouse_id;
        }
        $id = Warehouse::whereNull('deleted_at')->orderBy('id')->value('id');
        if (! $id) throw ValidationException::withMessages(['warehouse' => 'La empresa debe tener al menos un Centro de Distribución/Almacén activo.']);
        return (int) $id;
    }

    private function fromBaseQuantity(float $base, ?Unit $unit): float
    {
        if (! $unit || ! $unit->operator_value) return $base;
        return $unit->operator === '/' ? $base * (float) $unit->operator_value : $base / (float) $unit->operator_value;
    }
}
