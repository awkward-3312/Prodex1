<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\CashDrawer;
use App\Models\Sale;
use App\Models\SarFiscalDocument;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;

/**
 * Modern POS fiscal resolver. The source of truth is the operational identity of
 * the sale:
 *
 *   Branch -> InventoryLocation -> CashDrawer -> SAR Point -> Authorization -> CAI
 *
 * warehouse_id is NEVER consulted here. It stays on sar_points_of_issue / sales
 * only for the legacy resolver (parent) that handles non-POS or pre-location
 * sales.
 *
 * The chain below is strict on purpose: a sale can only ever consume the CAI /
 * correlativo range of its own branch. Every mismatch aborts with a message that
 * names the exact piece that is missing or inconsistent.
 */
class PosAwareSarFiscalSaleService extends SarFiscalSaleService
{
    public function issueIfEnabled(Sale $sale, ?int $cashDrawerId = null): ?SarFiscalDocument
    {
        // Not a modern location-native POS sale -> legacy warehouse_id resolver.
        if ((int) $sale->is_pos !== 1 || ! $sale->branch_id || ! $sale->inventory_location_id || ! $cashDrawerId) {
            return parent::issueIfEnabled($sale, $cashDrawerId);
        }

        $profile = SarFiscalProfile::first();
        if (! $profile || ! $profile->enabled) {
            return null;
        }

        $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
        if (! $drawer) {
            throw new SarFiscalException('La caja física seleccionada no existe o fue desactivada.');
        }
        if ((int) $drawer->branch_id !== (int) $sale->branch_id) {
            throw new SarFiscalException('La caja física seleccionada pertenece a otra sucursal. No puede facturar esta venta.');
        }
        if ($drawer->inventory_location_id !== null
            && (int) $drawer->inventory_location_id !== (int) $sale->inventory_location_id) {
            throw new SarFiscalException('La caja física no opera desde la ubicación de inventario de esta venta.');
        }

        $points = SarPointOfIssue::forOperationalContext(
            (int) $sale->branch_id,
            (int) $sale->inventory_location_id,
            (int) $cashDrawerId
        )->get();

        if ($points->count() > 1) {
            throw new SarFiscalException(
                'Hay más de un punto SAR activo para esta sucursal, ubicación y caja. Corrige la configuración fiscal.'
            );
        }

        if ($points->isEmpty()) {
            throw new SarFiscalException($this->missingPointMessage($sale, $drawer, $cashDrawerId));
        }

        $point = $points->first();

        // Defence in depth: the point must belong to this sale's branch.
        if ((int) $point->branch_id !== (int) $sale->branch_id) {
            throw new SarFiscalException('El punto SAR resuelto no pertenece a la sucursal de la venta.');
        }

        $authorization = $this->findActiveAuthorization($point);

        if ((int) $authorization->pointOfIssue->branch_id !== (int) $sale->branch_id) {
            throw new SarFiscalException('La autorización SAR resuelta no pertenece a la sucursal de la venta.');
        }

        return $this->issueWithAuthorization($sale, $authorization, $cashDrawerId);
    }

    private function missingPointMessage(Sale $sale, CashDrawer $drawer, int $cashDrawerId): string
    {
        $branchName = optional($sale->branch)->name ?: ('sucursal '.$sale->branch_id);
        $locationName = optional($sale->inventoryLocation)->name ?: ('ubicación '.$sale->inventory_location_id);
        $drawerName = $drawer->name ?: ('caja '.$cashDrawerId);

        return 'No hay un punto de emisión SAR activo para '.$branchName.' · '.$locationName.' · '.$drawerName
            .'. Créalo en Configuración → Facturación SAR antes de facturar desde esta caja.';
    }
}
