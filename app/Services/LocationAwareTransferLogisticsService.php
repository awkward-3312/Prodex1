<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Location-aware extension of the hardened/idempotent transfer receiver.
 *
 * Legacy warehouse transfers continue through the inherited implementation.
 * Modern transfers debit a physical InventoryLocation on dispatch and credit the
 * destination location only after the destination user confirms receipt.
 */
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

        // Resolve exactly the receipt produced by this call when an idempotency
        // token is available; otherwise use the newest receipt by this receiver.
        $receipt = DB::table('transfer_receipts')
            ->where('transfer_id', $transfer->id)
            ->when($requestToken, fn ($q) => $q->where('request_token', $requestToken))
            ->when(! $requestToken, fn ($q) => $q->where('received_by_user_id', $user->id))
            ->orderByDesc('id')
            ->first();

        if ($receipt) {
            DB::table('transfer_receipts')->where('id', $receipt->id)->update([
                'inventory_location_id' => (int) $transfer->to_inventory_location_id,
                'updated_at' => now(),
            ]);

            $this->creditDefectiveToQuarantine($transfer, (int) $receipt->id, $user);
        }

        return $updated->fresh(['from_warehouse', 'to_warehouse', 'fromInventoryLocation', 'toInventoryLocation', 'details.product']);
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

        // Role 1 is a global administrator; include active admins so a tenant can
        // never create an incoming shipment nobody is capable of receiving.
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

        // Keep the existing batch identity/pivots and aggregate batch history, then
        // mirror the received batch slices into the physical destination location.
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
