<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\CashDrawer;
use App\Models\Sale;
use App\Models\SarFiscalDocument;
use App\Models\SarPointOfIssue;

/**
 * Keeps SAR invoicing mandatory while adapting the legacy fiscal resolver to
 * the modern POS operational identity: Branch -> InventoryLocation -> CashDrawer.
 *
 * SAR points are still stored with a legacy warehouse_id today. For a modern
 * POS sale we resolve the fiscal point by the physical cash drawer first, then
 * expose that point's real legacy warehouse only in memory while the inherited
 * SAR issuer runs. The synthetic InventoryLocation id from the POS request is
 * never persisted into sales.warehouse_id.
 */
class PosAwareSarFiscalSaleService extends SarFiscalSaleService
{
    public function issueIfEnabled(Sale $sale, ?int $cashDrawerId = null): ?SarFiscalDocument
    {
        if ((int) $sale->is_pos !== 1 || ! $sale->branch_id || ! $sale->inventory_location_id || ! $cashDrawerId) {
            return parent::issueIfEnabled($sale, $cashDrawerId);
        }

        $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
        if (! $drawer || (int) $drawer->branch_id !== (int) $sale->branch_id) {
            throw new SarFiscalException('La caja física seleccionada no pertenece a la sucursal de la venta.');
        }

        $points = SarPointOfIssue::where('active', true)
            ->where('cash_drawer_id', $cashDrawerId)
            ->get();

        if ($points->count() > 1) {
            throw new SarFiscalException('Hay más de un punto SAR activo asignado a la misma caja física.');
        }

        if ($points->isEmpty()) {
            throw new SarFiscalException('No existe un punto SAR activo para la caja física seleccionada. Configura el punto de emisión antes de facturar.');
        }

        $point = $points->first();
        if (! $point->warehouse_id) {
            throw new SarFiscalException('El punto SAR de esta caja no tiene una referencia fiscal válida. Actualiza el punto de emisión antes de facturar.');
        }

        $originalWarehouseId = $sale->getAttribute('warehouse_id');
        $hadWarehouseRelation = $sale->relationLoaded('warehouse');
        $originalWarehouseRelation = $hadWarehouseRelation ? $sale->getRelation('warehouse') : null;

        try {
            // Compatibility is deliberately request-local/in-memory only. The sale
            // remains branch/location-native in the database.
            $sale->setAttribute('warehouse_id', (int) $point->warehouse_id);
            $sale->unsetRelation('warehouse');

            return parent::issueIfEnabled($sale, $cashDrawerId);
        } finally {
            $sale->setAttribute('warehouse_id', $originalWarehouseId);
            $sale->unsetRelation('warehouse');
            if ($hadWarehouseRelation) {
                $sale->setRelation('warehouse', $originalWarehouseRelation);
            }
        }
    }
}
