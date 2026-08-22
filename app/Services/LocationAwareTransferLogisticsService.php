<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use App\Models\TransferReceiptItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LocationAwareTransferLogisticsService extends IdempotentTransferLogisticsService
{
    public function userCanReceive(User $user, Transfer $transfer): bool
    {
        if (! $transfer->to_inventory_location_id) {
            return parent::userCanReceive($user, $transfer);
        }

        return $user->hasPermissionName(self::RECEIVE_PERMISSION)
            && app(InventoryLocationScopeService::class)->canAccess($user, (int) $transfer->to_inventory_location_id);
    }

    public function receive(
        Transfer $transfer,
        User $user,
        array $items,
        ?string $notes = null,
        ?string $requestToken = null
    ): Transfer {
        $updated = parent::receive($transfer, $user, $items, $notes, $requestToken);

        if (! $transfer->to_inventory_location_id || ! Schema::hasColumn('transfer_receipts', 'inventory_location_id')) {
            return $updated;
        }

        $receipt = DB::table('transfer_receipts')
            ->where('transfer_id', $transfer->id)
            ->when($requestToken, fn ($q) => $q->where('request_token', $requestToken))
            ->when(! $requestToken, fn ($q) => $q->where('received_by_user_id', $user->id))
            ->orderByDesc('id')
            ->first();

        if ($receipt) {
            DB::transaction(function () use ($transfer, $receipt, $user) {
                DB::table('transfer_receipts')->where('id', $receipt->id)->update([
                    'inventory_location_id' => (int) $transfer->to_inventory_location_id,
                    'updated_at' => now(),
                ]);

                $this->creditDefectiveToQuarantine($transfer, (int) $receipt->id, $user);
                $this->processMissingSerials($transfer, (int) $receipt->id);
            }, 3);
        }

        return $updated->fresh(['from_warehouse', 'to_warehouse', 'fromInventoryLocation', 'toInventoryLocation', 'details.product']);
    }

    /**
     * Reclassify an already-accounted issue into good stock without duplicating
     * physical inventory. Missing units are late arrivals (increase destination),
     * while defective units already exist in quarantine and therefore MOVE from
     * quarantine to the destination sellable location.
     */
    public function creditIssueResolution(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        ?string $issueColumn = null
    ): void {
        if (! $transfer->to_inventory_location_id) {
            parent::creditIssueResolution($transfer, $detail, $quantity, $receiptItem, $issueColumn);
            return;
        }
        if ($quantity <= 0) return;

        $product = Product::find($detail->product_id);
        if (! $product) {
            throw ValidationException::withMessages(['issue' => 'No se pudo identificar el producto de la incidencia.']);
        }

        $stockQty = app(TransferSerialLocationService::class)->baseQuantityForDetail($detail, $product, $quantity);
        $sourceIssue = $issueColumn ?: $this->inferResolvedIssueColumn($detail, $receiptItem, $product, $stockQty);
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

        if ($sourceIssue === 'quantity_defective') {
            $quarantineLocationId = $this->quarantineLocationIdForTransferDetail($transfer, $detail);
            if (! $quarantineLocationId) {
                throw ValidationException::withMessages([
                    'issue' => 'No se pudo identificar la ubicación de cuarentena de la mercancía defectuosa.',
                ]);
            }

            app(InventoryService::class)->move(
                $quarantineLocationId,
                (int) $transfer->to_inventory_location_id,
                (int) $detail->product_id,
                $stockQty,
                $variantId,
                [
                    'user_id' => auth()->id(),
                    'reference_type' => 'TransferIssueResolution',
                    'reference_id' => (string) $receiptItem->id,
                    'idempotency_key' => 'transfer:receipt:item:'.$receiptItem->id.':resolution:defective',
                    'notes' => 'Producto liberado de cuarentena a inventario vendible.',
                    'metadata' => ['transfer_id' => (int) $transfer->id, 'transfer_detail_id' => (int) $detail->id],
                ]
            );
        } else {
            app(InventoryService::class)->increase(
                (int) $transfer->to_inventory_location_id,
                (int) $detail->product_id,
                $stockQty,
                $variantId,
                [
                    'user_id' => auth()->id(),
                    'reference_type' => 'TransferIssueResolution',
                    'reference_id' => (string) $receiptItem->id,
                    'idempotency_key' => 'transfer:receipt:item:'.$receiptItem->id.':resolution:missing',
                    'notes' => 'Mercancía faltante recibida posteriormente en el destino.',
                    'metadata' => ['transfer_id' => (int) $transfer->id, 'transfer_detail_id' => (int) $detail->id],
                ]
            );
        }

        app(TransferSerialLocationService::class)->reclassifyIssueToGood(
            $transfer,
            $detail,
            $stockQty,
            $receiptItem,
            $sourceIssue
        );

        $this->creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);
    }

    public function notifyDestinationReceivers(Transfer $transfer): void
    {
        if (! $transfer->to_inventory_location_id) {
            parent::notifyDestinationReceivers($transfer);
            return;
        }
        if (! Schema::hasTable('transfer_notifications')) return;

        $location = InventoryLocation::active()->find($transfer->to_inventory_location_id);
        if (! $location) return;

        $candidateIds = collect();
        if (Schema::hasTable('user_inventory_locations')) {
            $candidateIds = $candidateIds->merge(
                DB::table('user_inventory_locations')
                    ->where('inventory_location_id', $location->id)
                    ->pluck('user_id')
            );
        }

        $candidateIds = $candidateIds->merge(
            User::where('default_inventory_location_id', $location->id)->pluck('id')
        );

        if ($location->branch_id && Schema::hasTable('user_branches')) {
            $candidateIds = $candidateIds->merge(
                DB::table('user_branches')->where('branch_id', $location->branch_id)->pluck('user_id')
            );
        }

        if (Schema::hasTable('user_operational_assignments')
            && Schema::hasColumn('user_operational_assignments', 'temporary_inventory_location_id')) {
            $candidateIds = $candidateIds->merge(
                DB::table('user_operational_assignments')
                    ->where('temporary_inventory_location_id', $location->id)
                    ->where('status', 'active')
                    ->where('starts_at', '<=', now())
                    ->where(function ($q) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                    })
                    ->pluck('user_id')
            );
        }

        $candidateIds = $candidateIds->merge(User::where('role_id', 1)->where('statut', 1)->pluck('id'));

        $users = User::whereIn('id', $candidateIds->filter()->unique()->values())
            ->where('statut', 1)
            ->get()
            ->filter(fn (User $candidate) => $this->userCanReceive($candidate, $transfer));

        $fromLabel = optional($transfer->fromInventoryLocation)->name
            ?: optional($transfer->from_warehouse)->name
            ?: 'El origen';
        $toLabel = $location->name;

        foreach ($users as $receiver) {
            DB::table('transfer_notifications')->updateOrInsert(
                [
                    'transfer_id' => $transfer->id,
                    'user_id' => $receiver->id,
                    'type' => 'incoming_transfer',
                ],
                [
                    'title' => 'Transferencia en camino',
                    'message' => sprintf('%s envió %s hacia %s.', $fromLabel, $transfer->Ref, $toLabel),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    protected function creditGoodStock(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem
    ): void {
        if (! $transfer->to_inventory_location_id) {
            parent::creditGoodStock($transfer, $detail, $quantity, $receiptItem);
            return;
        }

        $product = Product::find($detail->product_id);
        if (! $product) {
            throw ValidationException::withMessages(['transfer' => 'No se pudo identificar el producto recibido.']);
        }

        $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede acreditar '.$product->name.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

        $stockQty = $this->convertToBaseQuantity($quantity, $unit);
        app(InventoryService::class)->increase(
            (int) $transfer->to_inventory_location_id,
            (int) $detail->product_id,
            $stockQty,
            $detail->product_variant_id ? (int) $detail->product_variant_id : null,
            [
                'user_id' => auth()->id(),
                'reference_type' => 'TransferReceipt',
                'reference_id' => (string) $receiptItem->id,
                'idempotency_key' => 'transfer:receipt:item:'.$receiptItem->id.':good',
                'notes' => 'Mercancía recibida correctamente en la ubicación destino.',
                'metadata' => [
                    'transfer_id' => (int) $transfer->id,
                    'transfer_detail_id' => (int) $detail->id,
                    'receipt_item_id' => (int) $receiptItem->id,
                ],
            ]
        );

        app(TransferSerialLocationService::class)->receiveGood($transfer, $detail, $stockQty, $receiptItem);
        $this->creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);
    }

    protected function creditBatchStockIfApplicable(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem
    ): void {
        parent::creditBatchStockIfApplicable($transfer, $detail, $quantity, $receiptItem);

        if (! $transfer->to_inventory_location_id
            || ! Schema::hasTable('product_batch_location_stocks')
            || ! Schema::hasTable('product_batch_location_movements')
            || ! Schema::hasTable('transfer_receipt_item_batches')) {
            return;
        }

        $rows = DB::table('transfer_receipt_item_batches')
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->get();

        foreach ($rows as $row) {
            $batchId = (int) ($row->destination_batch_id ?: $row->source_batch_id);
            $qty = round((float) $row->quantity_good, 3);
            if ($batchId <= 0 || $qty <= 0) continue;

            $key = 'transfer:receipt:batch-row:'.$row->id.':location:'.$transfer->to_inventory_location_id;
            if (ProductBatchLocationMovement::where('idempotency_key', $key)->exists()) {
                continue;
            }

            DB::transaction(function () use ($batchId, $qty, $transfer, $receiptItem, $row, $key) {
                if (ProductBatchLocationMovement::where('idempotency_key', $key)->lockForUpdate()->exists()) {
                    return;
                }

                $stock = ProductBatchLocationStock::firstOrCreate(
                    [
                        'product_batch_id' => $batchId,
                        'inventory_location_id' => (int) $transfer->to_inventory_location_id,
                    ],
                    ['quantity' => 0, 'reserved_quantity' => 0]
                );
                $stock = ProductBatchLocationStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $stock->quantity = round((float) $stock->quantity + $qty, 3);
                $stock->save();

                ProductBatchLocationMovement::create([
                    'product_batch_id' => $batchId,
                    'from_inventory_location_id' => null,
                    'to_inventory_location_id' => (int) $transfer->to_inventory_location_id,
                    'quantity' => $qty,
                    'user_id' => auth()->id(),
                    'reference_type' => 'TransferReceiptBatch',
                    'reference_id' => (string) $row->id,
                    'idempotency_key' => $key,
                    'notes' => 'Lote acreditado físicamente por recepción de transferencia.',
                    'metadata' => [
                        'transfer_id' => (int) $transfer->id,
                        'receipt_item_id' => (int) $receiptItem->id,
                        'transfer_receipt_item_batch_id' => (int) $row->id,
                    ],
                ]);
            }, 3);
        }
    }

    private function creditDefectiveToQuarantine(Transfer $transfer, int $receiptId, User $user): void
    {
        if (! Schema::hasColumn('transfer_quarantine_stock', 'inventory_location_id')) return;

        $items = DB::table('transfer_receipt_items')
            ->where('transfer_receipt_id', $receiptId)
            ->where('quantity_defective', '>', 0)
            ->get();
        if ($items->isEmpty()) return;

        $destination = InventoryLocation::active()->find($transfer->to_inventory_location_id);
        if (! $destination) return;
        $quarantine = $this->quarantineLocation($destination);

        foreach ($items as $item) {
            $detail = TransferDetail::find($item->transfer_detail_id);
            if (! $detail) continue;

            $product = Product::find($detail->product_id);
            $unitId = $detail->purchase_unit_id ?: $product?->unit_purchase_id;
            $unit = $unitId ? Unit::find($unitId) : null;
            if (! $unit) continue;
            $baseQty = $this->convertToBaseQuantity((float) $item->quantity_defective, $unit);

            app(InventoryService::class)->increase(
                (int) $quarantine->id,
                (int) $detail->product_id,
                $baseQty,
                $detail->product_variant_id ? (int) $detail->product_variant_id : null,
                [
                    'user_id' => $user->id,
                    'reference_type' => 'TransferDefectiveReceipt',
                    'reference_id' => (string) $item->id,
                    'idempotency_key' => 'transfer:receipt:item:'.$item->id.':defective',
                    'notes' => 'Producto defectuoso recibido y aislado en cuarentena.',
                    'metadata' => ['transfer_id' => (int) $transfer->id, 'receipt_id' => $receiptId],
                ]
            );

            $receiptItem = TransferReceiptItem::find($item->id);
            if ($receiptItem) {
                app(TransferSerialLocationService::class)->receiveDefective(
                    $transfer,
                    $detail,
                    $baseQty,
                    $receiptItem,
                    (int) $quarantine->id
                );
            }

            DB::table('transfer_quarantine_stock')
                ->where('transfer_id', $transfer->id)
                ->where('transfer_detail_id', $detail->id)
                ->whereNull('inventory_location_id')
                ->update([
                    'inventory_location_id' => (int) $quarantine->id,
                    'updated_at' => now(),
                ]);
        }
    }

    private function processMissingSerials(Transfer $transfer, int $receiptId): void
    {
        if (! Schema::hasTable('transfer_detail_serials')) return;

        $items = TransferReceiptItem::where('transfer_receipt_id', $receiptId)
            ->where('quantity_missing', '>', 0)
            ->get();

        foreach ($items as $receiptItem) {
            $detail = TransferDetail::find($receiptItem->transfer_detail_id);
            if (! $detail) continue;
            $product = Product::find($detail->product_id);
            if (! $product || ! (bool) ($product->is_imei ?? false)) continue;

            $baseQty = app(TransferSerialLocationService::class)->baseQuantityForDetail(
                $detail,
                $product,
                (float) $receiptItem->quantity_missing
            );
            app(TransferSerialLocationService::class)->receiveMissing($transfer, $detail, $baseQty, $receiptItem);
        }
    }

    private function inferResolvedIssueColumn(
        TransferDetail $detail,
        TransferReceiptItem $receiptItem,
        Product $product,
        float $resolvedBaseQty
    ): string {
        if (! Schema::hasTable('transfer_detail_serials') || ! (bool) ($product->is_imei ?? false)) {
            // For non-serialized lines, a quarantine record proves the quantity was
            // defective; otherwise the resolution is a late missing arrival.
            return $this->quarantineLocationIdForTransferDetail($detail->transfer, $detail)
                ? 'quantity_defective'
                : 'quantity_missing';
        }

        $service = app(TransferSerialLocationService::class);
        $missingCurrent = $service->baseQuantityForDetail($detail, $product, (float) $receiptItem->quantity_missing);
        $defectiveCurrent = $service->baseQuantityForDetail($detail, $product, (float) $receiptItem->quantity_defective);
        $missingPivots = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->where('status', TransferDetailSerial::STATUS_MISSING)
            ->count();
        $defectivePivots = TransferDetailSerial::where('transfer_detail_id', $detail->id)
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->where('status', TransferDetailSerial::STATUS_DEFECTIVE)
            ->count();

        if (abs(($missingPivots - $missingCurrent) - $resolvedBaseQty) < 0.0005) {
            return 'quantity_missing';
        }
        if (abs(($defectivePivots - $defectiveCurrent) - $resolvedBaseQty) < 0.0005) {
            return 'quantity_defective';
        }

        throw ValidationException::withMessages([
            'issue' => 'No se pudo determinar con seguridad el tipo físico de la incidencia resuelta.',
        ]);
    }

    private function quarantineLocationIdForTransferDetail(Transfer $transfer, TransferDetail $detail): ?int
    {
        if (! Schema::hasTable('transfer_quarantine_stock') || ! Schema::hasColumn('transfer_quarantine_stock', 'inventory_location_id')) {
            return null;
        }

        $id = DB::table('transfer_quarantine_stock')
            ->where('transfer_id', $transfer->id)
            ->where('transfer_detail_id', $detail->id)
            ->whereNotNull('inventory_location_id')
            ->orderByDesc('id')
            ->value('inventory_location_id');

        return $id ? (int) $id : null;
    }

    private function quarantineLocation(InventoryLocation $destination): InventoryLocation
    {
        $query = InventoryLocation::active()->where(function ($q) {
            $q->where('is_quarantine', true)->orWhere('type', InventoryLocation::TYPE_QUARANTINE);
        });

        if ($destination->branch_id) $query->where('branch_id', $destination->branch_id);
        else $query->where('warehouse_id', $destination->warehouse_id);

        $existing = $query->first();
        if ($existing) return $existing;

        if ($destination->branch_id) {
            $branch = Branch::whereNull('deleted_at')->findOrFail($destination->branch_id);
            return app(InventoryLocationService::class)->createForBranch($branch, [
                'code' => 'QUAR',
                'name' => 'Cuarentena',
                'type' => InventoryLocation::TYPE_QUARANTINE,
                'is_quarantine' => true,
                'is_sellable' => false,
            ]);
        }

        $warehouse = \App\Models\Warehouse::whereNull('deleted_at')->findOrFail($destination->warehouse_id);
        return app(InventoryLocationService::class)->createForWarehouse($warehouse, [
            'code' => 'QUAR',
            'name' => 'Cuarentena',
            'type' => InventoryLocation::TYPE_QUARANTINE,
            'is_quarantine' => true,
            'is_sellable' => false,
        ]);
    }
}
