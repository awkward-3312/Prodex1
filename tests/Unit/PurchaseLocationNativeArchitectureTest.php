<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  PRE-LOCATION-NATIVE BASELINE  —  MS0 (test-only)
 * ============================================================================
 *
 * This test PINS the CURRENT (legacy) architecture of Purchases and Purchase
 * Returns so that MS1 / MS2 have to change these assertions ON PURPOSE. Every
 * assertion here describes what `main` does TODAY, not the target design.
 *
 * When a milestone lands, the matching constant / assertion below flips and the
 * diff shows exactly which legacy writer disappeared and which one is still
 * alive.
 *
 *   MS1  → PURCHASES_HAS_LOCATION_COLUMN / RETURNS_HAS_LOCATION_COLUMN become true
 *          LOCATION_AWARE_PURCHASE_SERVICE_EXISTS becomes true
 *   MS2  → PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES drops (store + import + …)
 *   MS3  → RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES drops
 *   MS5/6→ batch / serial writers move to the location engine
 */
class PurchaseLocationNativeArchitectureTest extends TestCase
{
    // ---- BASELINE CONSTANTS (audited on origin/main @ 58e0394) --------------

    /** `purchases` today has NO inventory_location_id / inventory_effect_snapshot. */
    private const PURCHASES_HAS_LOCATION_COLUMN = false;

    /** `purchase_returns` today has NO inventory_location_id / inventory_effect_snapshot. */
    private const RETURNS_HAS_LOCATION_COLUMN = false;

    /** No LocationAwarePurchaseStockService / LocationAwarePurchaseReturnStockService yet. */
    private const LOCATION_AWARE_PURCHASE_SERVICE_EXISTS = false;

    /**
     * `$product_warehouse->save();` sites in PurchasesController.
     * 6 legacy write contexts: store(+), update-reverse(-), update-reapply(+),
     * destroy(-), delete_by_selection(-) — each with a variant + a non-variant
     * branch (2 saves) — plus store_import_purchases(+) with a single branch
     * (1 save)  =>  5*2 + 1 = 11.
     */
    private const PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES = 11;

    /**
     * `$product_warehouse->save();` sites in PurchasesReturnController.
     * 5 legacy write contexts: store(-), update-reverse(+), update-reapply(-),
     * destroy(+), delete_by_selection(+) — each with a variant + a non-variant
     * branch  =>  5*2 = 10.
     */
    private const RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES = 10;

    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Body of `public function <name>(` up to the next `    public function `. */
    private function method(string $src, string $name): string
    {
        $start = strpos($src, "public function {$name}(");
        $this->assertNotFalse($start, "method {$name}() not found");
        $rest = substr($src, $start + 1);
        $end = strpos($rest, "\n    public function ");

        return $end === false ? substr($src, $start) : substr($src, $start, $end + 1);
    }

    // =====================================================================
    // 4 · BASELINE — schema / models have NO location context yet
    // =====================================================================

    public function test_baseline_purchases_migration_has_no_inventory_location_columns(): void
    {
        $mig = $this->read('database/migrations/tenant/2026_03_24_203803_create_purchases_table.php');
        $this->assertStringContainsString("Schema::create('purchases'", $mig);
        $this->assertStringContainsString("\$table->integer('warehouse_id')", $mig);

        $hasLocation = str_contains($mig, 'inventory_location_id')
            || str_contains($mig, 'inventory_effect_snapshot');
        $this->assertSame(
            self::PURCHASES_HAS_LOCATION_COLUMN,
            $hasLocation,
            'BASELINE: purchases has no inventory_location_id yet. When MS1 adds it, flip PURCHASES_HAS_LOCATION_COLUMN.'
        );

        // And no later add_* migration introduces it either.
        $dir = dirname(__DIR__, 2).'/database/migrations/tenant';
        foreach (glob($dir.'/*purchase*') as $file) {
            $body = file_get_contents($file);
            if (str_contains($body, "'purchases'") && str_contains($body, 'inventory_location_id')) {
                $this->fail('Unexpected inventory_location_id migration for purchases: '.basename($file));
            }
        }
    }

    public function test_baseline_purchase_returns_migration_has_no_inventory_location_columns(): void
    {
        $mig = $this->read('database/migrations/tenant/2026_03_24_203803_create_purchase_returns_table.php');
        $this->assertStringContainsString("Schema::create('purchase_returns'", $mig);

        $hasLocation = str_contains($mig, 'inventory_location_id')
            || str_contains($mig, 'inventory_effect_snapshot');
        $this->assertSame(self::RETURNS_HAS_LOCATION_COLUMN, $hasLocation);
    }

    public function test_baseline_purchase_models_have_no_inventory_location_fillable(): void
    {
        foreach (['Purchase', 'PurchaseReturn', 'PurchaseDetail', 'PurchaseReturnDetails'] as $model) {
            $src = $this->read("app/Models/{$model}.php");
            $this->assertStringNotContainsString(
                'inventory_location_id',
                $src,
                "BASELINE: {$model} has no inventory_location_id. MS1 will add it."
            );
            $this->assertStringNotContainsString('inventory_effect_snapshot', $src);
        }
    }

    // =====================================================================
    // 4 · BASELINE — controllers still hold the legacy product_warehouse writers
    // =====================================================================

    public function test_baseline_purchases_controller_has_legacy_product_warehouse_writers(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');

        $this->assertStringContainsString('use App\Models\product_warehouse;', $src);
        $this->assertStringContainsString('$product_warehouse->save();', $src);
        $this->assertStringContainsString("\$product_warehouse->qte += ", $src);
        $this->assertStringContainsString("\$product_warehouse->qte -= ", $src);

        // Per-method legacy writer presence (method => directions it must contain).
        $expected = [
            'store' => ['+='],
            'update' => ['-=', '+='],
            'destroy' => ['-='],
            'delete_by_selection' => ['-='],
            'store_import_purchases' => ['+='],
        ];
        foreach ($expected as $name => $dirs) {
            $body = $this->method($src, $name);
            $this->assertStringContainsString('$product_warehouse->save();', $body, "{$name}() lost its legacy PW writer");
            foreach ($dirs as $dir) {
                $this->assertStringContainsString("\$product_warehouse->qte {$dir} ", $body, "{$name}() lost its '{$dir}' legacy PW writer");
            }
        }
    }

    public function test_baseline_purchase_returns_controller_has_legacy_product_warehouse_writers(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');

        $this->assertStringContainsString('use App\Models\product_warehouse;', $src);
        $this->assertStringContainsString('$product_warehouse->save();', $src);

        $expected = [
            'store' => ['-='],
            'update' => ['+=', '-='],
            'destroy' => ['+='],
            'delete_by_selection' => ['+='],
        ];
        foreach ($expected as $name => $dirs) {
            $body = $this->method($src, $name);
            $this->assertStringContainsString('$product_warehouse->save();', $body, "{$name}() lost its legacy PW writer");
            foreach ($dirs as $dir) {
                $this->assertStringContainsString("\$product_warehouse->qte {$dir} ", $body, "{$name}() lost its '{$dir}' legacy PW writer");
            }
        }
    }

    public function test_baseline_purchases_controller_is_not_location_aware_yet(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach ([
            'inventory_location_id',
            'LocationAwarePurchase',
            'inventory_effect_snapshot',
            'InventoryService',
            'storeLocationAware',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "BASELINE: PurchasesController must NOT reference '{$needle}' yet (MS2 introduces it)."
            );
        }
    }

    public function test_baseline_purchase_returns_controller_is_not_location_aware_yet(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        foreach (['inventory_location_id', 'LocationAwarePurchaseReturn', 'inventory_effect_snapshot', 'InventoryService'] as $needle) {
            $this->assertStringNotContainsString($needle, $src);
        }
    }

    public function test_baseline_location_aware_purchase_services_do_not_exist_yet(): void
    {
        $base = dirname(__DIR__, 2).'/app/Services/';
        foreach (['LocationAwarePurchaseStockService.php', 'LocationAwarePurchaseReturnStockService.php'] as $file) {
            $this->assertSame(
                self::LOCATION_AWARE_PURCHASE_SERVICE_EXISTS,
                file_exists($base.$file),
                "BASELINE: {$file} is created in MS1. Flip LOCATION_AWARE_PURCHASE_SERVICE_EXISTS then."
            );
        }
    }

    public function test_baseline_no_purchase_inventory_locations_endpoint(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringNotContainsString('purchases_inventory_locations', $routes);
        $this->assertStringNotContainsString('purchases_return_inventory_locations', $routes);
    }

    // =====================================================================
    // 5 · WRITER INVENTORY — count the legacy product_warehouse write sites
    // =====================================================================

    /**
     * Guard that pins HOW MANY legacy `product_warehouse->save()` sites each
     * controller has. A drop here is expected at MS2 (purchases) / MS3
     * (returns); a change must be deliberate. Pattern-based, not line-based.
     */
    public function test_writer_inventory_legacy_product_warehouse_save_site_counts(): void
    {
        $purchases = $this->read('app/Http/Controllers/PurchasesController.php');
        $returns = $this->read('app/Http/Controllers/PurchasesReturnController.php');

        $this->assertSame(
            self::PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($purchases, '$product_warehouse->save();'),
            'PurchasesController legacy product_warehouse write sites changed — update the constant on purpose (MS2).'
        );
        $this->assertSame(
            self::RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($returns, '$product_warehouse->save();'),
            'PurchasesReturnController legacy product_warehouse write sites changed — update the constant on purpose (MS3).'
        );

        // The lookup that opens every write context is the same fingerprint.
        $lookup = "product_warehouse = product_warehouse::where('deleted_at', '=', null)";
        $this->assertSame(11, substr_count($purchases, $lookup), 'PurchasesController PW-lookup sites changed.');
        $this->assertSame(10, substr_count($returns, $lookup), 'PurchasesReturnController PW-lookup sites changed.');
    }

    /**
     * Human-readable map of the 6 + 5 legacy write CONTEXTS the migration will
     * dismantle. Each entry = (method, direction). If a method stops carrying
     * its direction, this fails and names it.
     */
    public function test_writer_inventory_context_map(): void
    {
        $contexts = [
            'PurchasesController' => [
                ['store', '+='],
                ['update', '-='],                    // reverse old effect
                ['update', '+='],                    // apply new effect
                ['destroy', '-='],
                ['delete_by_selection', '-='],
                ['store_import_purchases', '+='],
            ],
            'PurchasesReturnController' => [
                ['store', '-='],
                ['update', '+='],                    // reverse old effect
                ['update', '-='],                    // apply new effect
                ['destroy', '+='],
                ['delete_by_selection', '+='],
            ],
        ];

        $this->assertCount(6, $contexts['PurchasesController']);
        $this->assertCount(5, $contexts['PurchasesReturnController']);

        foreach ($contexts as $controller => $entries) {
            $src = $this->read("app/Http/Controllers/{$controller}.php");
            foreach ($entries as [$method, $dir]) {
                $body = $this->method($src, $method);
                $this->assertStringContainsString(
                    "\$product_warehouse->qte {$dir} ",
                    $body,
                    "{$controller}::{$method}() no longer performs a legacy '{$dir}' product_warehouse write."
                );
            }
        }
    }
}
