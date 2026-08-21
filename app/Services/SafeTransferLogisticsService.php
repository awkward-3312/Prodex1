<?php

namespace App\Services;

use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\Unit;

/**
 * Production binding for TransferLogisticsService.
 *
 * The legacy aggregate stock ledger stores base-unit quantities, while the
 * receiving UI works in the transfer line's purchase unit. This override makes
 * sure per-batch credits use the same converted base quantity as
 * product_warehouse, preventing drift for boxes/packs/strips and similar units.
 */
class SafeTransferLogisticsService extends TransferLogisticsService
{
    protected function creditGoodStock(Transfer $transfer, TransferDetail $detail, float $quantity, TransferReceiptItem $receiptItem): void
    {
        $unit = $detail->purchase_unit_id ? Unit::find($detail->purchase_unit_id) : null;
        $stockQty = $this->convertToBaseQuantity($quantity, $unit);

        $query = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->where('product_id', $detail->product_id)
            ->where(function ($q) use ($detail) {
                if ($detail->product_variant_id) {
                    $q->where('product_variant_id', $detail->product_variant_id);
                } else {
                    $q->whereNull('product_variant_id');
                }
            });

        $row = $query->lockForUpdate()->first();
        if (! $row) {
            $row = new product_warehouse();
            $row->warehouse_id = $transfer->to_warehouse_id;
            $row->product_id = $detail->product_id;
            $row->product_variant_id = $detail->product_variant_id;
            $row->qte = 0;
        }

        $row->qte = (float) $row->qte + $stockQty;
        $row->save();

        // transfer_detail_batches quantities are stored in base stock units. Credit
        // exactly the same base quantity to destination batches.
        $this->creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);
    }
}
