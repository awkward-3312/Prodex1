<?php

namespace App\Services;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferReceiptItem;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

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
    /**
     * Used when an already-accounted missing/defective quantity is later physically
     * recovered and reclassified as good stock. The optional issue column lets the
     * location-aware subclass distinguish a late arrival from quarantine release;
     * legacy warehouse accounting intentionally ignores it.
     */
    public function creditIssueResolution(
        Transfer $transfer,
        TransferDetail $detail,
        float $quantity,
        TransferReceiptItem $receiptItem,
        ?string $issueColumn = null
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $this->creditGoodStock($transfer, $detail, $quantity, $receiptItem);
    }

    protected function creditGoodStock(Transfer $transfer, TransferDetail $detail, float $quantity, TransferReceiptItem $receiptItem): void
    {
        $product = Product::find($detail->product_id);
        if (! $product) {
            throw ValidationException::withMessages([
                'transfer' => 'No se pudo identificar el producto de una línea recibida.',
            ]);
        }

        $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede acreditar '.$product->name.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

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

        $this->creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);
    }
}
