<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use App\Models\TransferReceiptItem;
use App\Models\Unit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferSerialLocationService
{
    public function isSupported(): bool
    {
        return Schema::hasTable('transfer_detail_serials')
            && Schema::hasTable('product_serials')
            && Schema::hasTable('product_serial_movements')
            && Schema::hasColumn('product_serials', 'inventory_location_id');
    }

    public function dispatchDetail(Transfer $transfer, TransferDetail $detail, Product $product, float $baseQuantity): void
    {
        if (! $this->isSupported() || ! (bool) ($product->is_imei ?? false)) return;

        $count = $this->serialCount($baseQuantity, $product->name);
        $existing = TransferDetailSerial::where('transfer_detail_id', $detail->id)->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            if ($existing->count() !== $count) {
                throw ValidationException::withMessages([
                    'transfer' => 'La asignación serializada existente no coincide con la cantidad de '.$product->name.'.',
                ]);
            }
            return;
        }

        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
        $query = ProductSerial::where('product_id', $detail->product_id)
            ->where('inventory_location_id', (int) $transfer->from_inventory_location_id)
            ->where('status', ProductSerial::STATUS_AVAILABLE);

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        $serials = $query->orderBy('id')->lockForUpdate()->limit($count)->get();
        if ($serials->count() !== $count) {
            throw ValidationException::withMessages([
                'transfer' => 'No hay suficientes números de serie/IMEI disponibles en la ubicación origen para '.$product->name.'.',
            ]);
        }

        foreach ($serials as $serial) {
            $fromLocation = $serial->inventory_location_id ? (int) $serial->inventory_location_id : null;
            $fromStatus = $serial->status;

            $serial->status = ProductSerial::STATUS_RESERVED;
            $serial->inventory_location_id = null;
            $serial->save();

            TransferDetailSerial::create([
                'transfer_detail_id' => $detail->id,
                'product_serial_id' => $serial->id,
                'status' => TransferDetailSerial::STATUS_IN_TRANSIT,
            ]);

            ProductSerialMovement::create([
                'product_serial_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'action' => ProductSerialMovement::ACTION_LOCATION_MOVED,
                'from_status' => $fromStatus,
                'to_status' => ProductSerial::STATUS_RESERVED,
                'warehouse_id' => $serial->warehouse_id,
                'from_inventory_location_id' => $fromLocation,
                'to_inventory_location_id' => null,
                'reference_type' => 'TransferDispatch',
                'reference_id' => (int) $transfer->id,
                'user_id' => auth()->id(),
                'notes' => 'Serial/IMEI puesto en tránsito. Detalle '.$detail->id.'.',
                'created_at' => now(),
            ]);
        }
    }

    public function receiveGood(Transfer $transfer, TransferDetail $detail, float $baseQuantity, TransferReceiptItem $receiptItem): void
    {
        $this->receiveSlice($transfer, $detail, $baseQuantity, $receiptItem, TransferDetailSerial::STATUS_RECEIVED, null, (int) $transfer->to_inventory_location_id);
    }

    public function receiveDefective(Transfer $transfer, TransferDetail $detail, float $baseQuantity, TransferReceiptItem $receiptItem, int $quarantineLocationId): void
    {
        $this->receiveSlice($transfer, $detail, $baseQuantity, $receiptItem, TransferDetailSerial::STATUS_DEFECTIVE, 'defective', $quarantineLocationId);
    }

    public function receiveMissing(Transfer $transfer, TransferDetail $detail, float $baseQuantity, TransferReceiptItem $receiptItem): void
    {
        $this->receiveSlice($transfer, $detail, $baseQuantity, $receiptItem, TransferDetailSerial::STATUS_MISSING, 'missing', null);
    }

    private function receiveSlice(
        Transfer $transfer,
        TransferDetail $detail,
        float $baseQuantity,
        TransferReceiptItem $receiptItem,
        string $pivotStatus,
        ?string $issueType,
        ?int $destinationLocationId
    ): void {
        if (! $this->isSupported()) return;

        $product = Product::find($detail->product_id);
        if (! $product || ! (bool) ($product->is_imei ?? false)) return;

        $count = $this->serialCount($baseQuantity, $product->name);
        if ($count === 0) return;

        $already = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->where('status', $pivotStatus)
            ->lockForUpdate()
            ->count();

        if ($already === $count) {
            return;
        }
        if ($already > 0) {
            throw ValidationException::withMessages([
                'transfer' => 'La recepción serializada de '.$product->name.' quedó parcialmente registrada y requiere revisión.',
            ]);
        }

        $pivots = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('status', TransferDetailSerial::STATUS_IN_TRANSIT)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($count)
            ->get();

        if ($pivots->count() !== $count) {
            throw ValidationException::withMessages([
                'transfer' => 'La recepción serializada de '.$product->name.' no coincide con los seriales/IMEI pendientes en tránsito.',
            ]);
        }

        foreach ($pivots as $pivot) {
            $serial = ProductSerial::whereKey($pivot->product_serial_id)->lockForUpdate()->first();
            if (! $serial || $serial->status !== ProductSerial::STATUS_RESERVED || $serial->inventory_location_id !== null) {
                throw ValidationException::withMessages([
                    'transfer' => 'Un serial/IMEI de '.$product->name.' ya no se encuentra en tránsito y la recepción fue detenida.',
                ]);
            }

            $fromStatus = $serial->status;
            if ($pivotStatus === TransferDetailSerial::STATUS_RECEIVED) {
                $serial->status = ProductSerial::STATUS_AVAILABLE;
                $serial->inventory_location_id = $destinationLocationId;
                if ($transfer->to_warehouse_id) $serial->warehouse_id = (int) $transfer->to_warehouse_id;
            } elseif ($pivotStatus === TransferDetailSerial::STATUS_DEFECTIVE) {
                $serial->status = ProductSerial::STATUS_DAMAGED;
                $serial->inventory_location_id = $destinationLocationId;
                if ($transfer->to_warehouse_id) $serial->warehouse_id = (int) $transfer->to_warehouse_id;
            } else {
                $serial->status = ProductSerial::STATUS_RESERVED;
                $serial->inventory_location_id = null;
            }
            $serial->save();

            $pivot->status = $pivotStatus;
            $pivot->issue_type = $issueType;
            $pivot->transfer_receipt_item_id = $receiptItem->id;
            $pivot->received_at = now();
            $pivot->save();

            ProductSerialMovement::create([
                'product_serial_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'action' => ProductSerialMovement::ACTION_LOCATION_MOVED,
                'from_status' => $fromStatus,
                'to_status' => $serial->status,
                'warehouse_id' => $serial->warehouse_id,
                'from_inventory_location_id' => null,
                'to_inventory_location_id' => $destinationLocationId,
                'reference_type' => $pivotStatus === TransferDetailSerial::STATUS_RECEIVED ? 'TransferReceipt' : 'TransferReceiptIssue',
                'reference_id' => (int) $receiptItem->id,
                'user_id' => auth()->id(),
                'notes' => $pivotStatus === TransferDetailSerial::STATUS_MISSING
                    ? 'Serial/IMEI reportado faltante durante recepción.'
                    : 'Serial/IMEI procesado durante recepción de transferencia.',
                'created_at' => now(),
            ]);
        }
    }

    public function baseQuantityForDetail(TransferDetail $detail, Product $product, float $lineQuantity): float
    {
        $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede procesar '.$product->name.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

        return round($unit->operator === '/'
            ? $lineQuantity / (float) $unit->operator_value
            : $lineQuantity * (float) $unit->operator_value, 3);
    }

    private function serialCount(float $baseQuantity, string $productName): int
    {
        if ($baseQuantity < 0) {
            throw ValidationException::withMessages(['transfer' => 'La cantidad de '.$productName.' no puede ser negativa.']);
        }
        $rounded = (int) round($baseQuantity);
        if (abs($baseQuantity - $rounded) > 0.0005) {
            throw ValidationException::withMessages([
                'transfer' => $productName.' usa serial/IMEI y solo puede transferirse en unidades físicas enteras.',
            ]);
        }
        return $rounded;
    }
}
