<?php

namespace App\Services;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationAwareSerialNumberService extends SerialNumberService
{
    /** Request-attribute prefix for the MS5-B1 POS serial preflight selection. */
    public const POS_SERIAL_PREFLIGHT_ATTR = 'prodex_pos_serial_preflight';

    /**
     * MS5-B1 — resolve, validate and DETERMINISTICALLY row-lock every serial of
     * the whole POS cart WITHOUT mutating anything, BEFORE the general decrease.
     * Runs inside CreatePOS's transaction, from
     * PosLocationSaleStockService::apply(), after the batch preflight.
     *
     * Only for a location-aware POS sale. Non-location sales return [] and their
     * apply path (parent::sellOnSale) is unchanged.
     *
     * Serials are explicit (client-provided), so the rows locked here are
     * exactly the ones sellOnSale() re-touches afterwards: it re-selects the
     * same (serial_number, product, location, status = available) rows, which
     * this transaction already holds — no NEW ProductSerial lock is acquired
     * after InventoryService::decrease.
     *
     * Does NOT change status, does NOT create ProductSerialMovement, does NOT
     * assign sale_id / sale_detail_id.
     *
     * @return array<int, array{product_id:int, product_variant_id:?int,
     *   serial_numbers:array<int,string>, product_serial_ids:array<int,int>}>
     *
     * @throws ValidationException
     */
    public function preflightSaleSerials(Sale $sale, array $inputDetails): array
    {
        if (! $sale->inventory_location_id || ! $this->isSupported()) {
            return [];
        }

        $locationId = (int) $sale->inventory_location_id;
        $plan = [];
        $allSerialIds = [];

        foreach (array_values($inputDetails) as $i => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || ! $this->productIsTracked($productId)) {
                continue;
            }

            $serials = $this->normalizeSerials($row['serial_numbers'] ?? null);
            if (empty($serials)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => 'Debes seleccionar los números de serie/IMEI que se están vendiendo.',
                ]);
            }

            $expected = (int) round((float) ($row['quantity'] ?? 0));
            if ($expected !== count($serials)) {
                throw ValidationException::withMessages([
                    'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta línea.",
                ]);
            }

            $variantId = ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;

            $ids = [];
            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', $productId)
                    ->where('inventory_location_id', $locationId)
                    ->where('status', ProductSerial::STATUS_AVAILABLE);
                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                $matchIds = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
                if (empty($matchIds)) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no está disponible en la ubicación de venta seleccionada.",
                    ]);
                }
                foreach ($matchIds as $id) {
                    $ids[] = $id;
                }
            }

            foreach ($ids as $id) {
                $allSerialIds[] = $id;
            }

            $plan[$i] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'serial_numbers' => $serials,
                'product_serial_ids' => array_values(array_unique($ids)),
            ];
        }

        // Deterministic lock: ProductSerial by id ASC, whole cart at once.
        $allSerialIds = array_values(array_unique($allSerialIds));
        if ($allSerialIds) {
            sort($allSerialIds, SORT_NUMERIC);
            $locked = ProductSerial::whereIn('id', $allSerialIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Revalidate every serial under lock.
            foreach ($plan as $line) {
                foreach ($line['product_serial_ids'] as $id) {
                    $serial = $locked->get($id);
                    if (! $serial
                        || (string) $serial->status !== ProductSerial::STATUS_AVAILABLE
                        || (int) $serial->product_id !== (int) $line['product_id']
                        || (int) $serial->inventory_location_id !== $locationId) {
                        throw ValidationException::withMessages([
                            'serial_numbers' => 'Un serial de la venta ya no está disponible en la ubicación de venta.',
                        ]);
                    }
                }
            }
        }

        return $plan;
    }

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

    public function returnFromSale(SaleReturn $return, SaleReturnDetails $detail, $serials): void
    {
        if (! $return->inventory_location_id) {
            parent::returnFromSale($return, $detail, $serials);
            return;
        }

        if (! $this->isSupported() || ! $this->productIsTracked($detail->product_id)) return;

        $serials = $this->normalizeSerials($serials);
        if (empty($serials)) return;

        $expected = (int) round((float) $detail->quantity);
        if ($expected !== count($serials)) {
            throw ValidationException::withMessages([
                'serial_numbers' => "Debes seleccionar exactamente {$expected} serial(es) para esta devolución.",
            ]);
        }

        DB::transaction(function () use ($return, $detail, $serials) {
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            foreach ($serials as $serialNumber) {
                $query = ProductSerial::where('serial_number', $serialNumber)
                    ->where('product_id', (int) $detail->product_id)
                    ->where('status', ProductSerial::STATUS_SOLD);

                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);

                if ($return->sale_id) {
                    $query->where('sale_id', (int) $return->sale_id);
                }

                $serial = $query->lockForUpdate()->first();
                if (! $serial) {
                    throw ValidationException::withMessages([
                        'serial_numbers' => "El serial {$serialNumber} no corresponde a la venta original o ya fue devuelto.",
                    ]);
                }

                $from = $serial->status;
                $serial->status = ProductSerial::STATUS_AVAILABLE;
                $serial->inventory_location_id = (int) $return->inventory_location_id;
                // warehouse_id remains as a legacy/logistical reference only.
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_SALE_RETURNED,
                    'from_status' => $from,
                    'to_status' => ProductSerial::STATUS_AVAILABLE,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => null,
                    'to_inventory_location_id' => (int) $return->inventory_location_id,
                    'reference_type' => 'SaleReturn',
                    'reference_id' => (int) $return->id,
                    'user_id' => auth()->id(),
                    'notes' => 'Serial devuelto a la ubicación física de la venta original.',
                    'created_at' => now(),
                ]);
            }
        }, 3);
    }
}
