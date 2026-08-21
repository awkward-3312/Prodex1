<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class TransferLocationDispatchService
{
    public function ensureDispatched(Transfer $transfer): void
    {
        if (! $transfer->from_inventory_location_id || ! $transfer->to_inventory_location_id) return;
        if (! $transfer->isApproved() || $transfer->statut !== 'sent') return;

        $details = TransferDetail::where('transfer_id', $transfer->id)->orderBy('id')->get();
        if ($details->isEmpty()) {
            throw ValidationException::withMessages(['transfer' => 'No se puede despachar una transferencia sin productos.']);
        }

        // Group repeated lines for the same physical SKU so the immutable inventory
        // ledger gets one deterministic/idempotent dispatch per SKU and transfer.
        $groups = [];
        foreach ($details as $detail) {
            $product = Product::find($detail->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['transfer' => 'Uno de los productos de la transferencia ya no existe.']);
            }
            $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
            $unit = $unitId ? Unit::find($unitId) : null;
            $base = $this->toBaseQuantity((float) $detail->quantity, $unit, $product->name);
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
            $key = (int) $detail->product_id.':'.($variantId ?: 0);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'product_id' => (int) $detail->product_id,
                    'variant_id' => $variantId,
                    'quantity' => 0.0,
                ];
            }
            $groups[$key]['quantity'] = round($groups[$key]['quantity'] + $base, 3);
        }

        foreach ($groups as $group) {
            app(InventoryService::class)->decrease(
                (int) $transfer->from_inventory_location_id,
                $group['product_id'],
                $group['quantity'],
                $group['variant_id'],
                [
                    'user_id' => auth()->id(),
                    'reference_type' => 'TransferDispatch',
                    'reference_id' => (string) $transfer->id,
                    'idempotency_key' => 'transfer:dispatch:'.$transfer->id.':product:'.$group['product_id'].':variant:'.($group['variant_id'] ?: 0),
                    'notes' => 'Mercancía despachada y puesta en tránsito.',
                    'metadata' => [
                        'transfer_id' => (int) $transfer->id,
                        'from_inventory_location_id' => (int) $transfer->from_inventory_location_id,
                        'to_inventory_location_id' => (int) $transfer->to_inventory_location_id,
                    ],
                ]
            );
        }
    }

    private function toBaseQuantity(float $quantity, ?Unit $unit, string $productName): float
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['transfer' => 'La cantidad de '.$productName.' debe ser mayor que cero.']);
        }
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede despachar '.$productName.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

        return round($unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value, 3);
    }
}
