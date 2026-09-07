<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Modern POS fiscal resolution is anchored to the operational identity of the
 * sale — Branch -> InventoryLocation -> CashDrawer -> SAR Point -> Authorization
 * -> CAI. warehouse_id is no longer the source of truth for POS invoicing; it
 * only survives for the legacy (non-POS / pre-location) resolver.
 */
class PosAwareSarFiscalSaleArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_modern_pos_resolves_the_point_through_branch_location_drawer(): void
    {
        $service = $this->read('app/Services/PosAwareSarFiscalSaleService.php');

        // The point is resolved through the drawer it covers (pivot), not by
        // warehouse, and only for a branch whose fiscal invoicing is enabled.
        $this->assertStringContainsString('->coveringDrawer($cashDrawerId)', $service);
        $this->assertStringContainsString('SarBranchSetting::where(\'branch_id\', $sale->branch_id)', $service);
        $this->assertStringContainsString('La facturación SAR no está habilitada para ', $service);
        $this->assertStringContainsString('(int) $sale->branch_id', $service);
        $this->assertStringContainsString('(int) $sale->inventory_location_id', $service);
        $this->assertStringContainsString('(int) $cashDrawerId', $service);

        // The in-memory warehouse_id swap hack is gone.
        $this->assertStringNotContainsString("setAttribute('warehouse_id'", $service);
        $this->assertStringNotContainsString('$originalWarehouseId', $service);

        // Cross-branch / cross-location drawers are rejected with clear messages.
        $this->assertStringContainsString('(int) $drawer->branch_id !== (int) $sale->branch_id', $service);
        $this->assertStringContainsString('pertenece a otra sucursal', $service);
        $this->assertStringContainsString('(int) $drawer->inventory_location_id !== (int) $sale->inventory_location_id', $service);

        // Missing-series message names the drawer and its branch.
        $this->assertStringContainsString('todavía no está cubierta por ninguna serie fiscal SAR', $service);

        // Defence in depth: the resolved series must belong to the sale's branch.
        $this->assertStringContainsString('La serie fiscal resuelta no pertenece a la sucursal de la venta.', $service);
    }

    public function test_sar_stays_mandatory_and_pos_aware_service_is_the_binding(): void
    {
        $provider = $this->read('app/Providers/AppServiceProvider.php');
        $legacy = $this->read('app/Services/SarFiscalSaleService.php');
        $modern = $this->read('app/Services/PosAwareSarFiscalSaleService.php');

        $this->assertStringContainsString('singleton(SarFiscalSaleService::class, PosAwareSarFiscalSaleService::class)', $provider);

        // Both entry points still short-circuit when fiscal invoicing is disabled.
        $this->assertStringContainsString('if (! $profile || ! $profile->enabled)', $legacy);
        $this->assertStringContainsString('if (! $profile || ! $profile->enabled)', $modern);

        // The legacy warehouse resolver survives only as a fallback.
        $this->assertStringContainsString('return parent::issueIfEnabled($sale, $cashDrawerId);', $modern);
        $this->assertStringContainsString('->where(\'warehouse_id\', $warehouseId)', $legacy);
    }

    public function test_correlativo_allocation_has_a_hard_cross_branch_guard(): void
    {
        $number = $this->read('app/Services/SarFiscalNumberService.php');

        // The counter lives on the authorisation of the series; the allocation
        // point re-checks that the series belongs to the sale's branch.
        $this->assertStringContainsString('$series->branch_id', $number);
        $this->assertStringContainsString('Una venta no puede consumir el CAI de una sucursal distinta.', $number);
        // The lock is scoped to ONE series' authorisation rows, nothing wider —
        // not the fiscal profile, not the sales table.
        $this->assertStringContainsString('forSeries($pointOfIssueId, $documentType)', $number);
        $this->assertStringContainsString('->lockForUpdate()', $number);
        $this->assertStringNotContainsString('SarFiscalProfile::query()->lockForUpdate()', $number);
        $this->assertStringNotContainsString("where('sale_id', \$sale->id)->lockForUpdate()", $number);
        // Consistent lock order (by id) so two concurrent allocations can't deadlock.
        $this->assertMatchesRegularExpression('/forSeries\(\$pointOfIssueId, \$documentType\)\s*->whereIn\([^)]*\)\s*->orderBy\(\x27id\x27\)\s*->lockForUpdate\(\)/s', $number);
    }
}
