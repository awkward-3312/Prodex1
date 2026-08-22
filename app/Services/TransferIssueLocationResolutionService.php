<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferIssueLocationResolutionService
{
    public function apply(
        object $issue,
        Transfer $transfer,
        TransferDetail $detail,
        string $resolutionCode,
        ?int $actorUserId,
        ?int $quarantineLocationId
    ): void {
        if (! $transfer->to_inventory_location_id || $issue->type !== 'defective') return;
        if (! in_array($resolutionCode, ['written_off', 'returned_to_origin'], true)) return;
        if (! $quarantineLocationId) {
            throw ValidationException::withMessages([
                'issue' => 'No se pudo identificar la ubicación física de cuarentena de la incidencia.',
            ]);
        }

        $product = Product::find($detail->product_id);
        if (! $product) {
            throw ValidationException::withMessages(['issue' => 'No se pudo identificar el producto de la incidencia.']);
        }
        $qty = app(TransferSerialLocationService::class)->baseQuantityForDetail($detail, $product, (float) $issue->quantity);
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

        $targetLocationId = null;
        if ($resolutionCode === 'written_off') {
            app(InventoryService::class)->decrease(
                $quarantineLocationId,
                (int) $detail->product_id,
                $qty,
                $variantId,
                [
                    'user_id' => $actorUserId,
                    'reference_type' => 'TransferDiscrepancyWriteOff',
                    'reference_id' => (string) $issue->id,
                    'idempotency_key' => 'transfer:discrepancy:'.$issue->id.':written-off',
                    'notes' => 'Producto defectuoso dado de baja desde cuarentena.',
                    'metadata' => ['transfer_id' => (int) $transfer->id],
                ]
            );
        } else {
            $source = InventoryLocation::active()->find($transfer->from_inventory_location_id);
            if (! $source) {
                throw ValidationException::withMessages(['issue' => 'La ubicación de origen de la transferencia ya no está activa.']);
            }
            $target = $this->quarantineForOwner($source);
            $targetLocationId = (int) $target->id;

            app(InventoryService::class)->move(
                $quarantineLocationId,
                $targetLocationId,
                (int) $detail->product_id,
                $qty,
                $variantId,
                [
                    'user_id' => $actorUserId,
                    'reference_type' => 'TransferDiscrepancyReturn',
                    'reference_id' => (string) $issue->id,
                    'idempotency_key' => 'transfer:discrepancy:'.$issue->id.':returned-to-origin',
                    'notes' => 'Producto defectuoso devuelto a cuarentena del origen.',
                    'metadata' => ['transfer_id' => (int) $transfer->id],
                ]
            );
        }

        $this->applySerialDisposition(
            $issue,
            $transfer,
            $detail,
            $product,
            $qty,
            $resolutionCode,
            $actorUserId,
            $targetLocationId
        );
    }

    private function applySerialDisposition(
        object $issue,
        Transfer $transfer,
        TransferDetail $detail,
        Product $product,
        float $baseQuantity,
        string $resolutionCode,
        ?int $actorUserId,
        ?int $targetLocationId
    ): void {
        if (! (bool) ($product->is_imei ?? false)
            || ! Schema::hasTable('transfer_detail_serials')
            || ! Schema::hasTable('product_serials')) {
            return;
        }

        $count = (int) round($baseQuantity);
        if (abs($baseQuantity - $count) > 0.0005) {
            throw ValidationException::withMessages(['issue' => 'Un producto serializado no puede resolverse con una cantidad fraccionaria.']);
        }

        $marker = $resolutionCode === 'written_off' ? 'written_off' : 'returned_to_origin';
        $already = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('status', TransferDetailSerial::STATUS_DEFECTIVE)
            ->where('issue_type', $marker)
            ->count();
        if ($already === $count) return;
        if ($already > 0) {
            throw ValidationException::withMessages(['issue' => 'La disposición de seriales/IMEI quedó parcialmente aplicada.']);
        }

        $pivots = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('status', TransferDetailSerial::STATUS_DEFECTIVE)
            ->where(function ($query) {
                $query->whereNull('issue_type')->orWhere('issue_type', 'defective');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->limit($count)
            ->get();

        if ($pivots->count() !== $count) {
            throw ValidationException::withMessages(['issue' => 'Los seriales/IMEI defectuosos no coinciden con la incidencia resuelta.']);
        }

        foreach ($pivots as $pivot) {
            $serial = ProductSerial::whereKey($pivot->product_serial_id)->lockForUpdate()->first();
            if (! $serial || $serial->status !== ProductSerial::STATUS_DAMAGED) {
                throw ValidationException::withMessages(['issue' => 'Un serial/IMEI defectuoso cambió de estado antes de cerrar la incidencia.']);
            }

            $fromLocation = $serial->inventory_location_id ? (int) $serial->inventory_location_id : null;
            $serial->inventory_location_id = $resolutionCode === 'written_off' ? null : $targetLocationId;
            if ($resolutionCode === 'returned_to_origin' && $transfer->from_warehouse_id) {
                $serial->warehouse_id = (int) $transfer->from_warehouse_id;
            }
            $serial->save();

            $pivot->issue_type = $marker;
            $pivot->save();

            ProductSerialMovement::create([
                'product_serial_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'action' => ProductSerialMovement::ACTION_LOCATION_MOVED,
                'from_status' => ProductSerial::STATUS_DAMAGED,
                'to_status' => ProductSerial::STATUS_DAMAGED,
                'warehouse_id' => $serial->warehouse_id,
                'from_inventory_location_id' => $fromLocation,
                'to_inventory_location_id' => $serial->inventory_location_id,
                'reference_type' => 'TransferDiscrepancy',
                'reference_id' => (int) $issue->id,
                'user_id' => $actorUserId,
                'notes' => $resolutionCode === 'written_off'
                    ? 'Serial/IMEI defectuoso dado de baja.'
                    : 'Serial/IMEI defectuoso devuelto a cuarentena del origen.',
                'created_at' => now(),
            ]);
        }
    }

    private function quarantineForOwner(InventoryLocation $source): InventoryLocation
    {
        $query = InventoryLocation::active()->where(function ($q) {
            $q->where('is_quarantine', true)->orWhere('type', InventoryLocation::TYPE_QUARANTINE);
        });

        if ($source->branch_id) $query->where('branch_id', $source->branch_id);
        else $query->where('warehouse_id', $source->warehouse_id);

        $existing = $query->first();
        if ($existing) return $existing;

        if ($source->branch_id) {
            $branch = Branch::whereNull('deleted_at')->findOrFail($source->branch_id);
            return app(InventoryLocationService::class)->createForBranch($branch, [
                'code' => 'QUAR', 'name' => 'Cuarentena', 'type' => InventoryLocation::TYPE_QUARANTINE,
                'is_quarantine' => true, 'is_sellable' => false,
            ]);
        }

        $warehouse = \App\Models\Warehouse::whereNull('deleted_at')->findOrFail($source->warehouse_id);
        return app(InventoryLocationService::class)->createForWarehouse($warehouse, [
            'code' => 'QUAR', 'name' => 'Cuarentena', 'type' => InventoryLocation::TYPE_QUARANTINE,
            'is_quarantine' => true, 'is_sellable' => false,
        ]);
    }
}
