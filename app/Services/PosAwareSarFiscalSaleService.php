<?php

namespace App\Services;

use App\Exceptions\SarFiscalException;
use App\Models\CashDrawer;
use App\Models\Sale;
use App\Models\SarBranchSetting;
use App\Models\SarFiscalDocument;
use App\Models\SarFiscalProfile;
use App\Models\SarPointOfIssue;

/**
 * Modern POS fiscal resolver. PRODEX manages the SAR structure per branch, so
 * resolution is fully automatic:
 *
 *   Sale.branch_id
 *     -> InventoryLocation / CashDrawer of the sale
 *     -> "Facturación SAR habilitada" for that branch
 *     -> the FISCAL SERIES (sar_points_of_issue) that covers this cash drawer
 *     -> its authorisation (active, or the prepared "next" one) + CAI
 *     -> correlativo, allocated atomically by SarFiscalNumberService
 *
 * The correlativo counter belongs to the authorisation of the series, never to
 * the cash drawer or the branch. The branch is used only for security and
 * ownership: a sale can only consume a series that belongs to its own branch.
 * warehouse_id is never consulted here — the legacy parent resolver handles
 * non-POS / pre-branch sales.
 */
class PosAwareSarFiscalSaleService extends SarFiscalSaleService
{
    public function issueIfEnabled(Sale $sale, ?int $cashDrawerId = null): ?SarFiscalDocument
    {
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

        $branchName = optional($sale->branch)->name ?: ('sucursal '.$sale->branch_id);

        $setting = SarBranchSetting::where('branch_id', $sale->branch_id)->first();
        if (! $setting || ! $setting->enabled) {
            throw new SarFiscalException(
                'La facturación SAR no está habilitada para '.$branchName
                .'. Actívala en Configuración → Facturación SAR.'
            );
        }

        $series = $this->resolveSeriesForDrawer((int) $sale->branch_id, (int) $sale->inventory_location_id, (int) $cashDrawerId);

        if (! $series) {
            $drawerName = $drawer->name ?: ('caja '.$cashDrawerId);
            throw new SarFiscalException(
                'La caja '.$drawerName.' de '.$branchName.' todavía no está cubierta por ninguna serie fiscal SAR. '
                .'Asígnala a una serie en Configuración → Facturación SAR.'
            );
        }

        if ((int) $series->branch_id !== (int) $sale->branch_id) {
            throw new SarFiscalException('La serie fiscal resuelta no pertenece a la sucursal de la venta.');
        }
        if (! $series->hasCodes()) {
            throw new SarFiscalException(
                'La serie fiscal de '.$branchName.' está incompleta: falta el código de establecimiento o de punto de emisión.'
            );
        }

        // Friendly pre-flight; SarFiscalNumberService re-validates and, if the
        // active CAI has just run out, switches to the prepared "next" one — all
        // under a row lock, inside the sale-creation transaction.
        $this->assertSeriesHasAuthorization($series);

        return $this->issueForSeries($sale, $series, self::DOCUMENT_TYPE, $cashDrawerId);
    }

    /**
     * The fiscal series that covers this cash drawer. Preferred path is the
     * explicit series<->drawers pivot; falls back to the legacy single-drawer /
     * branch+location+drawer columns for series created before the pivot.
     */
    private function resolveSeriesForDrawer(int $branchId, int $locationId, int $cashDrawerId): ?SarPointOfIssue
    {
        $covering = SarPointOfIssue::query()
            ->coveringDrawer($cashDrawerId)
            ->where('branch_id', $branchId)
            ->get();

        if ($covering->count() > 1) {
            throw new SarFiscalException('Hay más de una serie fiscal activa que cubre esta caja física. Corrige la configuración fiscal.');
        }
        if ($covering->count() === 1) {
            return $covering->first();
        }

        $legacy = SarPointOfIssue::query()
            ->where('active', true)
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($locationId, $cashDrawerId) {
                $q->where('cash_drawer_id', $cashDrawerId)
                    ->orWhere(function ($qq) use ($locationId, $cashDrawerId) {
                        $qq->where('inventory_location_id', $locationId)->where('cash_drawer_id', $cashDrawerId);
                    });
            })
            ->get();

        if ($legacy->count() > 1) {
            throw new SarFiscalException('Hay más de una serie fiscal activa para esta sucursal y caja. Corrige la configuración fiscal.');
        }

        return $legacy->first();
    }
}
