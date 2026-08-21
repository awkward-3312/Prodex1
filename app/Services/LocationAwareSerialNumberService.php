<?php

namespace App\Services;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Sale;
use App\Models\SaleDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationAwareSerialNumberService extends SerialNumberService
{
    public function sellOnSale(Sale $sale, SaleDetail $detail, $serials): void
    {
        if (! $sale->inventory_location_id) {
            parent::sellOnSale($sale, $detail, $serials);
            return;
        }

        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) return;

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => 'Debes seleccionar los números de serie/IMEI que se están vendiendo.',
            ]);
        }

        $expected = (int) round((float) $detail->quantity);
        if ($expected !== count($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta línea.",
            ]);
        }

        DB::transaction(function () use ($sale, $detail, $serials) {
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', (int) $detail->product_id)
                    ->where('inventory_location_id', (int) $sale->inventory_location_id)
                    ->where('status', ProductSerial::STATUS_AVAILABLE);

                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                $serial = $query->lockForUpdate()->first();
                if (! $serial) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no está disponible en la ubicación de venta seleccionada.",
                    ]);
                }

                $from = $serial->status;
                $serial->status = ProductSerial::STATUS_SOLD;
                $serial->sale_id = (int) $sale->id;
                $serial->sale_detail_id = (int) $detail->id;
                $serial->client_id = $sale->client_id ? (int) $sale->client_id : null;
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_SOLD,
                    'from_status' => $from,
                    'to_status' => ProductSerial::STATUS_SOLD,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => (int) $sale->inventory_location_id,
                    'to_inventory_location_id' => null,
                    'reference_type' => 'Sale',
                    'reference_id' => (int) $sale->id,
                    'user_id' => auth()->id(),
                    'notes' => 'Serial vendido desde la ubicación operativa del POS.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }
}
