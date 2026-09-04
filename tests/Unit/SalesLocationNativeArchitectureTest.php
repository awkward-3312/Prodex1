<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  LOCATION-NATIVE ADMIN SALES / SALE RETURNS — architecture contract (MS7-B1)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers (source shifts constantly).
 * Pins the structural invariants §39 of the MS7-B1 spec requires:
 *   - explicit location-primary routing (store/update/destroy/bulk, both controllers)
 *   - is_pos stays 0 for Admin Sale (never impersonates POS)
 *   - POS production code untouched by this milestone
 *   - GENERAL never writes product_warehouse for a native document
 *   - BATCH/SERIAL apply order precedes GENERAL, both directions
 *   - snapshot is the GENERAL reverse authority (reverseSnapshot, not a
 *     re-derivation from SaleDetail rows)
 *   - SalesReturnController explicitly owns general+batch+serial (no bridge
 *     double-write)
 *   - ecommerce / WooCommerce / Shopify / reports / promotion / readiness gate
 *     stay untouched by this milestone
 */
class SalesLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    // ------------------------------------------------------------------ schema

    public function test_snapshot_migration_exists_and_is_additive_only(): void
    {
        $src = $this->read('database/migrations/tenant/2026_09_04_000000_add_inventory_effect_snapshot_to_sales_and_returns.php');
        $this->assertStringContainsString("'sales'", $src);
        $this->assertStringContainsString("'sale_returns'", $src);
        $this->assertStringContainsString('inventory_effect_snapshot', $src);
        $this->assertStringContainsString('hasColumn', $src);
        $this->assertStringNotContainsString('dropColumn(\'inventory_location_id\'', $src);
    }

    // ------------------------------------------------------------------ SalesController

    public function test_sales_controller_routes_store_update_destroy_bulk_to_native(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');

        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('__isNativeSale', $src);
        // Admin Sale native never impersonates POS.
        $this->assertStringContainsString('$order->is_pos = 0;', $src);
        // update/destroy/bulk route off the PERSISTED document identity, not
        // the warehouse's current mode.
        $this->assertMatchesRegularExpression('/is_pos.{0,40}!==\s*1/s', $src);
    }

    public function test_sales_controller_never_writes_product_warehouse_when_native(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');
        // Every product_warehouse::where(...)->qte write site in store/update/
        // bulk-delete must be reachable only under a "! __isNativeSale" guard.
        $this->assertMatchesRegularExpression('/! ?\$__isNativeSale.{0,80}statut/s', $src);
        $this->assertStringContainsString('never touches product_warehouse', $src);
    }

    public function test_sales_controller_batch_serial_precede_general_both_directions(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');
        // apply: batch/serial calls textually precede the GENERAL snapshot apply.
        $batchApplyPos = strpos($src, 'applyForSaleWithAutoFallback');
        $generalApplyPos = strpos($src, 'buildNativeSaleSnapshot');
        $this->assertNotFalse($batchApplyPos);
        $this->assertNotFalse($generalApplyPos);
        $this->assertLessThan($generalApplyPos, $batchApplyPos, 'BATCH apply must precede the GENERAL snapshot apply.');

        // reverse (bulk delete): batch/serial reverseForSaleDetails calls
        // textually precede the reverseLocationNativeSaleGeneral() call.
        $reverseArtifactPos = strpos($src, '$batchService->reverseForSaleDetails($old_sale_details);');
        $reverseGeneralPos = strpos($src, '$this->reverseLocationNativeSaleGeneral($current_Sale);');
        $this->assertNotFalse($reverseArtifactPos);
        $this->assertNotFalse($reverseGeneralPos);
        $this->assertLessThan($reverseGeneralPos, $reverseArtifactPos, 'BATCH/SERIAL reverse must precede the GENERAL reverse.');
    }

    public function test_sales_controller_snapshot_is_the_reverse_authority(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');
        $this->assertStringContainsString('reverseSnapshot', $src);
        $this->assertStringContainsString('normalizeSnapshot', $src);
        $this->assertStringContainsString('FAIL CLOSED', $src);
    }

    public function test_sales_controller_exposes_native_location_endpoints(): void
    {
        $src = $this->read('app/Http/Controllers/SalesController.php');
        $this->assertStringContainsString('function inventoryLocationsForWarehouse', $src);
        $this->assertStringContainsString('function inventoryLocationCatalog', $src);
        // Outbound scope (like PurchaseReturn), not inbound.
        $this->assertStringContainsString('allowedLocationIds', $src);
    }

    // ------------------------------------------------------------------ SalesReturnController

    public function test_sales_return_controller_routes_all_crud_to_native(): void
    {
        $src = $this->read('app/Http/Controllers/SalesReturnController.php');
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('__isNativeReturn', $src);
        $this->assertSame(4, substr_count($src, '__isNativeReturn = '), 'expected exactly 4 routing sites: store, update, destroy, bulk delete.');
    }

    public function test_sales_return_controller_owns_general_batch_and_serial_explicitly(): void
    {
        $src = $this->read('app/Http/Controllers/SalesReturnController.php');
        $this->assertStringContainsString('LocationAwareSaleStockService::class', $src);
        $this->assertStringContainsString('applyForSaleReturnWithAutoFallback', $src);
        $this->assertStringContainsString('applyForSaleReturn(', $src);
        $this->assertStringContainsString('reverseForSaleReturn', $src);
        // No new bridge/bypass — never re-introduces a raw product_warehouse
        // write guarded only by legacy conditions once native.
        $this->assertStringContainsString('NEVER touches product_warehouse', $src);
    }

    public function test_sales_return_controller_destination_is_never_auto_assumed_from_sale(): void
    {
        $src = $this->read('app/Http/Controllers/SalesReturnController.php');
        $this->assertStringContainsString('RETURN DESTINATION', $src);
        $this->assertStringContainsString('never auto-assumed from the referenced', $src);
    }

    public function test_sales_return_controller_has_over_return_guard(): void
    {
        $src = $this->read('app/Http/Controllers/SalesReturnController.php');
        $this->assertStringContainsString('function assertReturnWithinSoldQuantity', $src);
        $this->assertStringContainsString('assertReturnWithinSoldQuantity(', $src);
    }

    public function test_sales_return_controller_exposes_native_location_endpoints_with_inbound_scope(): void
    {
        $src = $this->read('app/Http/Controllers/SalesReturnController.php');
        $this->assertStringContainsString('function inventoryLocationsForWarehouse', $src);
        $this->assertStringContainsString('function inventoryLocationCatalog', $src);
        // Inbound scope (like Purchase), not outbound.
        $this->assertStringContainsString('receivingLocationIds', $src);
    }

    // ------------------------------------------------------------------ Sale / SaleReturn models

    public function test_sale_return_location_fallback_never_overrides_explicit_selection(): void
    {
        $src = $this->read('app/Models/SaleReturn.php');
        $this->assertStringContainsString("if (\$return->inventory_location_id) return;", $src);
        $this->assertStringContainsString('return goods to a different valid location', $src);
    }

    // ------------------------------------------------------------------ Serial service overrides

    public function test_serial_service_reverse_overrides_are_fail_closed(): void
    {
        $src = $this->read('app/Services/LocationAwareSerialNumberService.php');
        $this->assertStringContainsString('function reverseForSaleDetails', $src);
        $this->assertStringContainsString('function reverseForSaleReturn(', $src);
        $this->assertMatchesRegularExpression('/reverseForSaleDetails.*?FAIL CLOSED/s', $src);
        $this->assertMatchesRegularExpression('/reverseForSaleReturn.*?FAIL CLOSED/s', $src);
    }

    // ------------------------------------------------------------------ POS isolation

    public function test_pos_production_code_is_untouched_by_ms7_b1(): void
    {
        foreach ([
            'app/Http/Controllers/PosController.php',
            'app/Services/PosLocationSaleStockService.php',
            'app/Services/PosLocationStockBridge.php',
            'app/Services/PosLocationArtifactPreflightService.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringNotContainsString('MS7-B1', $src, "{$rel} must stay untouched by MS7-B1 (§19).");
        }
    }

    // ------------------------------------------------------------------ out-of-scope surfaces untouched

    public function test_out_of_scope_surfaces_are_untouched_by_ms7_b1(): void
    {
        foreach ([
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/ReportController.php',
            'app/Http/Controllers/StoreFrontController.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B1', $src, "{$rel} must stay untouched by MS7-B1 (out of scope).");
        }
    }
}
