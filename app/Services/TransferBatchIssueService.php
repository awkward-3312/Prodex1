<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\TransferReceiptItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferBatchIssueService
{
    public function isSupported(): bool
    {
        return Schema::hasTable('transfer_detail_batches')
            && Schema::hasTable('transfer_receipt_item_batches')
            && Schema::hasTable('transfer_receipt_item_batch_issues')
            && Schema::hasTable('product_batches')
            && Schema::hasTable('product_batch_location_stocks')
            && Schema::hasTable('product_batch_location_movements');
    }

    public function allocateGood(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        int $destinationLocationId
    ): void {
        if (! $this->isSupported() || $quantity <= 0 || ! $this->hasBatchManifest($detail)) return;

        $existing = (float) DB::table('transfer_receipt_item_batches')
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->sum('quantity_good');
        if (abs($existing - $quantity) <= 0.0005) return;
        if ($existing > 0) {
            throw ValidationException::withMessages(['transfer' => 'La asignación de lotes de esta recepción quedó parcialmente registrada.']);
        }

        $this->allocate($transfer, $detail, $quantity, function ($pivot, $take) use ($transfer, $receiptItem, $destinationLocationId) {
            $source = $pivot->sourceBatch;
            $destination = $this->destinationBatch($transfer, $pivot, $source);
            $destination->qty = round((float) $destination->qty + $take, 6);
            $destination->save();

            $rowId = DB::table('transfer_receipt_item_batches')->insertGetId([
                'transfer_receipt_item_id' => $receiptItem->id,
                'transfer_detail_batch_id' => $pivot->id,
                'source_batch_id' => $pivot->source_batch_id,
                'destination_batch_id' => $destination->id,
                'quantity_good' => $take,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->increaseBatchLocation(
                (int) $destination->id,
                $destinationLocationId,
                $take,
                'TransferReceiptBatch',
                (string) $rowId,
                'transfer:receipt:batch-row:'.$rowId.':location:'.$destinationLocationId,
                'Lote recibido correctamente en la ubicación destino.',
                ['transfer_id' => (int) $transfer->id, 'receipt_item_id' => (int) $receiptItem->id]
            );

            if (! $pivot->dest_batch_id) {
                $pivot->dest_batch_id = $destination->id;
                $pivot->save();
            }
        });
    }

    public function allocateIssue(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        string $issueType,
        ?int $inventoryLocationId = null
    ): void {
        if (! $this->isSupported() || $quantity <= 0 || ! $this->hasBatchManifest($detail)) return;
        if (! in_array($issueType, ['defective', 'missing'], true)) {
            throw ValidationException::withMessages(['transfer' => 'Tipo de incidencia de lote inválido.']);
        }

        $existing = (float) DB::table('transfer_receipt_item_batch_issues')
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->where('issue_type', $issueType)
            ->sum('quantity');
        if (abs($existing - $quantity) <= 0.0005) return;
        if ($existing > 0) {
            throw ValidationException::withMessages(['transfer' => 'La asignación de lotes de una incidencia quedó parcialmente registrada.']);
        }

        if ($issueType === 'defective' && ! $inventoryLocationId) {
            throw ValidationException::withMessages(['transfer' => 'La incidencia defectuosa no tiene ubicación de cuarentena.']);
        }

        $this->allocate($transfer, $detail, $quantity, function ($pivot, $take) use ($transfer, $receiptItem, $issueType, $inventoryLocationId) {
            $source = $pivot->sourceBatch;
            $destination = $issueType === 'defective' ? $this->destinationBatch($transfer, $pivot, $source) : null;

            $rowId = DB::table('transfer_receipt_item_batch_issues')->insertGetId([
                'transfer_receipt_item_id' => $receiptItem->id,
                'transfer_detail_batch_id' => $pivot->id,
                'source_batch_id' => $pivot->source_batch_id,
                'destination_batch_id' => $destination?->id,
                'inventory_location_id' => $inventoryLocationId,
                'issue_type' => $issueType,
                'quantity' => $take,
                'resolved_quantity' => 0,
                'resolution_status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($issueType === 'defective' && $destination) {
                $this->increaseBatchLocation(
                    (int) $destination->id,
                    (int) $inventoryLocationId,
                    $take,
                    'TransferDefectiveBatch',
                    (string) $rowId,
                    'transfer:batch-issue:'.$rowId.':defective',
                    'Lote defectuoso recibido y aislado en cuarentena.',
                    ['transfer_id' => (int) $transfer->id, 'receipt_item_id' => (int) $receiptItem->id]
                );
            }
        });
    }

    public function reclassifyToGood(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        string $issueType,
        int $destinationLocationId
    ): void {
        if (! $this->isSupported() || $quantity <= 0 || ! $this->hasBatchManifest($detail)) return;
        if (! in_array($issueType, ['defective', 'missing'], true)) return;

        $rows = DB::table('transfer_receipt_item_batch_issues')
            ->where('transfer_receipt_item_id', $receiptItem->id)
            ->where('issue_type', $issueType)
            ->whereRaw('resolved_quantity < quantity')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = round($quantity, 6);
        foreach ($rows as $row) {
            if ($remaining <= 0.0005) break;
            $open = round((float) $row->quantity - (float) $row->resolved_quantity, 6);
            $take = min($open, $remaining);
            if ($take <= 0) continue;

            $pivot = TransferDetailBatch::with('sourceBatch')->find($row->transfer_detail_batch_id);
            if (! $pivot?->sourceBatch) {
                throw ValidationException::withMessages(['issue' => 'No se pudo recuperar el lote original de la incidencia.']);
            }
            $destination = $row->destination_batch_id
                ? ProductBatch::find($row->destination_batch_id)
                : $this->destinationBatch($transfer, $pivot, $pivot->sourceBatch);
            if (! $destination) {
                throw ValidationException::withMessages(['issue' => 'No se pudo recuperar el lote destino de la incidencia.']);
            }

            $destination->qty = round((float) $destination->qty + $take, 6);
            $destination->save();

            $fingerprint = number_format((float) $row->resolved_quantity + $take, 6, '.', '');
            if ($issueType === 'defective') {
                $this->moveSameBatch(
                    (int) $destination->id,
                    (int) $row->inventory_location_id,
                    $destinationLocationId,
                    $take,
                    'TransferBatchIssueResolution',
                    (string) $row->id,
                    'transfer:batch-issue:'.$row->id.':release:'.$fingerprint,
                    'Lote liberado de cuarentena a inventario vendible.'
                );
            } else {
                $this->increaseBatchLocation(
                    (int) $destination->id,
                    $destinationLocationId,
                    $take,
                    'TransferBatchIssueResolution',
                    (string) $row->id,
                    'transfer:batch-issue:'.$row->id.':late:'.$fingerprint,
                    'Lote faltante recibido posteriormente.',
                    ['transfer_id' => (int) $transfer->id]
                );
            }

            $resolved = round((float) $row->resolved_quantity + $take, 6);
            DB::table('transfer_receipt_item_batch_issues')->where('id', $row->id)->update([
                'destination_batch_id' => (int) $destination->id,
                'resolved_quantity' => $resolved,
                'resolution_status' => $resolved + 0.0005 >= (float) $row->quantity ? 'resolved' : 'partially_resolved',
                'resolution_code' => $issueType === 'defective' ? 'released_to_stock' : 'received_later',
                'resolved_at' => $resolved + 0.0005 >= (float) $row->quantity ? now() : null,
                'updated_at' => now(),
            ]);

            $remaining = round($remaining - $take, 6);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages(['issue' => 'La cantidad por lote de la incidencia no coincide con la resolución solicitada.']);
        }
    }

    public function resolveDisposition(
        object $issue,
        Transfer $transfer,
        TransferDetail $detail,
        string $resolutionCode,
        ?int $targetOriginQuarantineLocationId = null
    ): void {
        if (! $this->isSupported() || ! $this->hasBatchManifest($detail)) return;

        if ($issue->type === 'missing') {
            if (in_array($resolutionCode, ['confirmed_loss', 'reconciled_by_adjustment'], true)) {
                $this->markNoStockResolution($detail, 'missing', (float) $issue->quantity, $resolutionCode);
            }
            return;
        }

        if ($issue->type !== 'defective') return;
        if (! in_array($resolutionCode, ['written_off', 'returned_to_origin', 'reconciled_by_adjustment'], true)) return;

        if ($resolutionCode === 'reconciled_by_adjustment') {
            $this->markNoStockResolution($detail, 'defective', (float) $issue->quantity, $resolutionCode);
            return;
        }
        if ($resolutionCode === 'returned_to_origin' && ! $targetOriginQuarantineLocationId) {
            throw ValidationException::withMessages(['issue' => 'No se pudo identificar la cuarentena del origen para los lotes devueltos.']);
        }

        $rows = DB::table('transfer_receipt_item_batch_issues as bi')
            ->join('transfer_receipt_items as ri', 'ri.id', '=', 'bi.transfer_receipt_item_id')
            ->where('ri.transfer_detail_id', $detail->id)
            ->where('bi.issue_type', 'defective')
            ->whereRaw('bi.resolved_quantity < bi.quantity')
            ->orderBy('bi.id')
            ->lockForUpdate()
            ->get(['bi.*']);

        $remaining = round((float) $issue->quantity, 6);
        foreach ($rows as $row) {
            if ($remaining <= 0.0005) break;
            $open = round((float) $row->quantity - (float) $row->resolved_quantity, 6);
            $take = min($open, $remaining);
            if ($take <= 0) continue;

            $destinationBatchId = (int) $row->destination_batch_id;
            if ($destinationBatchId <= 0 || ! $row->inventory_location_id) {
                throw ValidationException::withMessages(['issue' => 'La incidencia defectuosa perdió su identidad de lote o cuarentena.']);
            }

            $fingerprint = number_format((float) $row->resolved_quantity + $take, 6, '.', '');
            if ($resolutionCode === 'written_off') {
                $this->decreaseBatchLocation(
                    $destinationBatchId,
                    (int) $row->inventory_location_id,
                    $take,
                    'TransferBatchWriteOff',
                    (string) $row->id,
                    'transfer:batch-issue:'.$row->id.':writeoff:'.$fingerprint,
                    'Lote defectuoso dado de baja desde cuarentena.'
                );
            } else {
                $this->returnToSourceBatch(
                    $destinationBatchId,
                    (int) $row->source_batch_id,
                    (int) $row->inventory_location_id,
                    (int) $targetOriginQuarantineLocationId,
                    $take,
                    (int) $row->id,
                    $fingerprint
                );
            }

            $resolved = round((float) $row->resolved_quantity + $take, 6);
            DB::table('transfer_receipt_item_batch_issues')->where('id', $row->id)->update([
                'resolved_quantity' => $resolved,
                'resolution_status' => $resolved + 0.0005 >= (float) $row->quantity ? 'resolved' : 'partially_resolved',
                'resolution_code' => $resolutionCode,
                'resolved_at' => $resolved + 0.0005 >= (float) $row->quantity ? now() : null,
                'updated_at' => now(),
            ]);
            $remaining = round($remaining - $take, 6);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages(['issue' => 'La cantidad defectuosa por lote no coincide con la incidencia.']);
        }
    }

    private function allocate(Transfer $transfer, TransferDetail $detail, float $quantity, callable $consumer): void
    {
        $pivots = TransferDetailBatch::with('sourceBatch')
            ->where('transfer_detail_id', $detail->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($pivots->isEmpty()) return;

        $remaining = round($quantity, 6);
        foreach ($pivots as $pivot) {
            if ($remaining <= 0.0005) break;
            if (! $pivot->sourceBatch) continue;

            $good = (float) DB::table('transfer_receipt_item_batches')
                ->where('transfer_detail_batch_id', $pivot->id)
                ->sum('quantity_good');
            $issues = (float) DB::table('transfer_receipt_item_batch_issues')
                ->where('transfer_detail_batch_id', $pivot->id)
                ->sum('quantity');
            $available = max(0, round((float) $pivot->qty - $good - $issues, 6));
            $take = min($available, $remaining);
            if ($take <= 0) continue;

            $consumer($pivot, $take);
            $remaining = round($remaining - $take, 6);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages(['transfer' => 'La distribución por lotes no cubre toda la cantidad recibida.']);
        }
    }

    private function hasBatchManifest(TransferDetail $detail): bool
    {
        return TransferDetailBatch::where('transfer_detail_id', $detail->id)->exists();
    }

    private function destinationBatch(Transfer $transfer, TransferDetailBatch $pivot, ProductBatch $source): ProductBatch
    {
        $destination = ProductBatch::where('warehouse_id', $transfer->to_warehouse_id)
            ->where('product_id', $source->product_id)
            ->where('batch_no', $source->batch_no)
            ->where(function ($query) use ($source) {
                $source->product_variant_id
                    ? $query->where('product_variant_id', $source->product_variant_id)
                    : $query->whereNull('product_variant_id');
            })
            ->lockForUpdate()
            ->first();

        if ($destination) return $destination;

        return ProductBatch::create([
            'product_id' => $source->product_id,
            'product_variant_id' => $source->product_variant_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'batch_no' => $source->batch_no,
            'expiry_date' => $source->expiry_date,
            'mfg_date' => $source->mfg_date,
            'qty' => 0,
            'unit_cost' => $pivot->unit_cost ?? $source->unit_cost,
            'provider_id' => $source->provider_id,
            'status' => 'active',
            'notes' => 'Identidad de lote recibida mediante '.$transfer->Ref,
        ]);
    }

    private function increaseBatchLocation(int $batchId, int $locationId, float $quantity, string $referenceType, string $referenceId, string $key, string $notes, array $metadata = []): void
    {
        if (ProductBatchLocationMovement::where('idempotency_key', $key)->exists()) return;
        $stock = ProductBatchLocationStock::firstOrCreate(
            ['product_batch_id' => $batchId, 'inventory_location_id' => $locationId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
        $stock = ProductBatchLocationStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $stock->quantity = round((float) $stock->quantity + $quantity, 6);
        $stock->save();

        ProductBatchLocationMovement::create([
            'product_batch_id' => $batchId,
            'from_inventory_location_id' => null,
            'to_inventory_location_id' => $locationId,
            'quantity' => $quantity,
            'user_id' => auth()->id(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $key,
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }

    private function decreaseBatchLocation(int $batchId, int $locationId, float $quantity, string $referenceType, string $referenceId, string $key, string $notes): void
    {
        if (ProductBatchLocationMovement::where('idempotency_key', $key)->exists()) return;
        $stock = ProductBatchLocationStock::where('product_batch_id', $batchId)
            ->where('inventory_location_id', $locationId)->lockForUpdate()->first();
        if (! $stock || (float) $stock->quantity + 0.0005 < $quantity) {
            throw ValidationException::withMessages(['issue' => 'La existencia física del lote en cuarentena es insuficiente.']);
        }
        $stock->quantity = round((float) $stock->quantity - $quantity, 6);
        $stock->save();

        ProductBatchLocationMovement::create([
            'product_batch_id' => $batchId,
            'from_inventory_location_id' => $locationId,
            'to_inventory_location_id' => null,
            'quantity' => $quantity,
            'user_id' => auth()->id(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $key,
            'notes' => $notes,
        ]);
    }

    private function moveSameBatch(int $batchId, int $fromLocationId, int $toLocationId, float $quantity, string $referenceType, string $referenceId, string $key, string $notes): void
    {
        if (ProductBatchLocationMovement::where('idempotency_key', $key)->exists()) return;
        $source = ProductBatchLocationStock::where('product_batch_id', $batchId)
            ->where('inventory_location_id', $fromLocationId)->lockForUpdate()->first();
        if (! $source || (float) $source->quantity + 0.0005 < $quantity) {
            throw ValidationException::withMessages(['issue' => 'La existencia física del lote origen es insuficiente.']);
        }
        $target = ProductBatchLocationStock::firstOrCreate(
            ['product_batch_id' => $batchId, 'inventory_location_id' => $toLocationId],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
        $target = ProductBatchLocationStock::whereKey($target->id)->lockForUpdate()->firstOrFail();

        $source->quantity = round((float) $source->quantity - $quantity, 6);
        $source->save();
        $target->quantity = round((float) $target->quantity + $quantity, 6);
        $target->save();

        ProductBatchLocationMovement::create([
            'product_batch_id' => $batchId,
            'from_inventory_location_id' => $fromLocationId,
            'to_inventory_location_id' => $toLocationId,
            'quantity' => $quantity,
            'user_id' => auth()->id(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $key,
            'notes' => $notes,
        ]);
    }

    private function returnToSourceBatch(int $destinationBatchId, int $sourceBatchId, int $fromLocationId, int $toLocationId, float $quantity, int $issueRowId, string $fingerprint): void
    {
        $outKey = 'transfer:batch-issue:'.$issueRowId.':return-out:'.$fingerprint;
        $inKey = 'transfer:batch-issue:'.$issueRowId.':return-in:'.$fingerprint;
        $this->decreaseBatchLocation($destinationBatchId, $fromLocationId, $quantity, 'TransferBatchReturn', (string) $issueRowId, $outKey, 'Lote defectuoso salió de cuarentena destino para volver al origen.');
        $this->increaseBatchLocation($sourceBatchId, $toLocationId, $quantity, 'TransferBatchReturn', (string) $issueRowId, $inKey, 'Lote defectuoso regresó a cuarentena del origen.');
    }

    private function markNoStockResolution(TransferDetail $detail, string $issueType, float $quantity, string $resolutionCode): void
    {
        $rows = DB::table('transfer_receipt_item_batch_issues as bi')
            ->join('transfer_receipt_items as ri', 'ri.id', '=', 'bi.transfer_receipt_item_id')
            ->where('ri.transfer_detail_id', $detail->id)
            ->where('bi.issue_type', $issueType)
            ->whereRaw('bi.resolved_quantity < bi.quantity')
            ->orderBy('bi.id')
            ->lockForUpdate()
            ->get(['bi.*']);

        $remaining = round($quantity, 6);
        foreach ($rows as $row) {
            if ($remaining <= 0.0005) break;
            $open = round((float) $row->quantity - (float) $row->resolved_quantity, 6);
            $take = min($open, $remaining);
            if ($take <= 0) continue;
            $resolved = round((float) $row->resolved_quantity + $take, 6);
            DB::table('transfer_receipt_item_batch_issues')->where('id', $row->id)->update([
                'resolved_quantity' => $resolved,
                'resolution_status' => $resolved + 0.0005 >= (float) $row->quantity ? 'resolved' : 'partially_resolved',
                'resolution_code' => $resolutionCode,
                'resolved_at' => $resolved + 0.0005 >= (float) $row->quantity ? now() : null,
                'updated_at' => now(),
            ]);
            $remaining = round($remaining - $take, 6);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages(['issue' => 'La cantidad por lote no coincide con la incidencia resuelta.']);
        }
    }
}
