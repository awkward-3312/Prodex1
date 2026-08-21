<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Services\BatchLocationService;
use App\Services\InventoryLocationScopeService;
use App\Services\InventoryService;
use App\Services\SerialLocationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosLocationInventoryController extends BaseController
{
    public function show(Request $request, int $locationId, int $productId)
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

        $product = Product::whereNull('deleted_at')->findOrFail($productId);
        $variantId = $request->filled('product_variant_id')
            ? (int) $request->input('product_variant_id')
            : null;

        $inventory = app(InventoryService::class);
        $payload = [
            'inventory_location' => [
                'id' => (int) $location->id,
                'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
                'code' => (string) $location->code,
                'name' => (string) $location->name,
            ],
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
}
