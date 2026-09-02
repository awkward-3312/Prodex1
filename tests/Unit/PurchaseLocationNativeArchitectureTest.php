<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  LOCATION-NATIVE PURCHASES — architecture contract
 * ============================================================================
 *
 *  MS0  (1e7289e)  — pinned the fully-legacy baseline.
 *  MS1  (this)     — schema + models + engine PREPARED, controllers STILL legacy.
 *
 * The legacy writer counts MUST NOT move at MS1: schema + service must not
 * activate any behaviour. They drop deliberately at:
 *   MS2 → PurchasesController legacy product_warehouse writers
 *   MS3 → PurchasesReturnController legacy product_warehouse writers
 *   MS5/6 → batch / serial writers move to the location engine
 */
class PurchaseLocationNativeArchitectureTest extends TestCase
{
    /** MS1 additive migration (same pattern as PR #81 adjustments/damages). */
    private const MS1_MIGRATION =
        'database/migrations/tenant/2026_09_02_000000_add_inventory_location_to_purchases_and_returns.php';

    /**
     * `$product_warehouse->save();` sites in PurchasesController — UNCHANGED at MS1.
     * 6 write contexts: store(+), update-reverse(-), update-reapply(+), destroy(-),
     * delete_by_selection(-) each with a variant + non-variant branch (2 saves),
     * plus store_import_purchases(+) with a single branch  =>  5*2 + 1 = 11.
     */
    private const PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES = 11;

    /**
     * `$product_warehouse->save();` sites in PurchasesReturnController — UNCHANGED at MS1.
     * 5 write contexts, each with a variant + non-variant branch  =>  5*2 = 10.
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
    // MS1 · SCHEMA — additive nullable columns for both documents
    // =====================================================================

    public function test_ms1_migration_adds_inventory_location_columns_to_both_documents(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/'.self::MS1_MIGRATION);
        $mig = $this->read(self::MS1_MIGRATION);

        // both tables covered by the same loop.
        $this->assertMatchesRegularExpression("/\\['purchases',\\s*'purchase_returns'\\]/", $mig);

        // nullable + indexed integer + json snapshot, same shape as PR #81.
        $this->assertStringContainsString("\$t->integer('inventory_location_id')->nullable()->index()", $mig);
        $this->assertStringContainsString("\$t->json('inventory_effect_snapshot')->nullable()", $mig);

        // additive & safe: hasColumn guards on up(), dropColumn on down().
        $this->assertStringContainsString("Schema::hasColumn(\$table, 'inventory_location_id')", $mig);
        $this->assertStringContainsString('function down()', $mig);
        $this->assertStringContainsString("\$t->dropColumn(\$col)", $mig);

        // no branch_id column, no foreign key, no data backfill.
        $this->assertStringNotContainsString("('branch_id')", $mig);
        $this->assertStringNotContainsString('->foreign(', $mig);
        $this->assertStringNotContainsString('DB::table(', $mig);
    }

    public function test_ms1_original_create_migrations_are_untouched(): void
    {
        // The CREATE tables still have warehouse_id only — the new columns are
        // added by the separate additive migration, never retro-fitted here.
        foreach ([
            'database/migrations/tenant/2026_03_24_203803_create_purchases_table.php',
            'database/migrations/tenant/2026_03_24_203803_create_purchase_returns_table.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString("\$table->integer('warehouse_id')", $src);
            $this->assertStringNotContainsString('inventory_location_id', $src);
            $this->assertStringNotContainsString('inventory_effect_snapshot', $src);
        }
    }

    // =====================================================================
    // MS1 · MODELS — prepared (fillable + array cast + relation)
    // =====================================================================

    public function test_ms1_purchase_models_are_prepared(): void
    {
        foreach (['Purchase', 'PurchaseReturn'] as $model) {
            $src = $this->read("app/Models/{$model}.php");
            $this->assertStringContainsString("'inventory_location_id', 'inventory_effect_snapshot'", $src);
            $this->assertStringContainsString("'inventory_location_id' => 'integer'", $src);
            $this->assertStringContainsString("'inventory_effect_snapshot' => 'array'", $src);
            $this->assertStringContainsString('function inventoryLocation()', $src);
            $this->assertStringContainsString("belongsTo(InventoryLocation::class, 'inventory_location_id')", $src);
        }
    }

    public function test_ms1_detail_models_stay_untouched(): void
    {
        // Only the document headers carry the snapshot; detail rows are unchanged.
        foreach (['PurchaseDetail', 'PurchaseReturnDetails'] as $model) {
            $src = $this->read("app/Models/{$model}.php");
            $this->assertStringNotContainsString('inventory_location_id', $src);
            $this->assertStringNotContainsString('inventory_effect_snapshot', $src);
        }
    }

    // =====================================================================
    // MS1 · SERVICE — the engine exists and is location-native pure
    // =====================================================================

    public function test_ms1_location_aware_purchase_stock_service_exists_and_is_pure(): void
    {
        $rel = 'app/Services/LocationAwarePurchaseStockService.php';
        $this->assertFileExists(dirname(__DIR__, 2).'/'.$rel);
        $src = $this->read($rel);

        $this->assertStringContainsString('class LocationAwarePurchaseStockService', $src);

        // Uses InventoryService as its only writer (constructor-injected).
        $this->assertStringContainsString('InventoryService $inventory', $src);
        $this->assertStringContainsString('$this->inventory->increase(', $src);
        $this->assertStringContainsString('$this->inventory->decrease(', $src);

        // Location-native PURE: never the legacy per-warehouse model/table,
        // never the dual-write mirror.
        $this->assertStringNotContainsString('product_warehouse', $src);
        $this->assertStringNotContainsString('mirrorLegacySnapshot', $src);
        $this->assertStringNotContainsString('adjustTo(', $src);

        // Explicit reference types (MS7 will teach the provenance auditor about them).
        foreach (['Purchase', 'PurchaseReversal', 'PurchaseReturn', 'PurchaseReturnReversal'] as $ref) {
            $this->assertStringContainsString("'{$ref}'", $src);
        }

        // Contract guards from the reference engine.
        $this->assertStringContainsString('DB::transactionLevel() <= 0', $src);
        $this->assertStringContainsString('is_batch_tracked', $src);
        $this->assertStringContainsString('is_imei', $src);
        $this->assertStringContainsString('SNAPSHOT_VERSION', $src);
        $this->assertStringContainsString("'revision'", $src);
    }

    // =====================================================================
    // MS1 · CONTROLLERS — STILL fully legacy
    // =====================================================================

    public function test_purchases_controller_still_holds_the_legacy_writers(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');

        $this->assertStringContainsString('use App\Models\product_warehouse;', $src);

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

    public function test_purchase_returns_controller_still_holds_the_legacy_writers(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        $this->assertStringContainsString('use App\Models\product_warehouse;', $src);

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

    public function test_controllers_are_not_wired_to_the_location_engine_yet(): void
    {
        foreach ([
            'PurchasesController' => ['inventory_location_id', 'LocationAwarePurchase', 'inventory_effect_snapshot', 'InventoryService', 'storeLocationAware'],
            'PurchasesReturnController' => ['inventory_location_id', 'LocationAwarePurchaseReturn', 'inventory_effect_snapshot', 'InventoryService'],
        ] as $ctrl => $needles) {
            $src = $this->read("app/Http/Controllers/{$ctrl}.php");
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $src,
                    "MS1: {$ctrl} must NOT reference '{$needle}' yet — MS2/MS3 wire it."
                );
            }
        }
    }

    public function test_no_purchase_inventory_locations_endpoint_yet(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringNotContainsString('purchases_inventory_locations', $routes);
        $this->assertStringNotContainsString('purchases_return_inventory_locations', $routes);
    }

    // =====================================================================
    // WRITER INVENTORY — legacy product_warehouse save sites (MUST be intact)
    // =====================================================================

    public function test_writer_inventory_legacy_product_warehouse_save_site_counts_are_unchanged(): void
    {
        $purchases = $this->read('app/Http/Controllers/PurchasesController.php');
        $returns = $this->read('app/Http/Controllers/PurchasesReturnController.php');

        $this->assertSame(
            self::PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($purchases, '$product_warehouse->save();'),
            'MS1 must NOT move any legacy product_warehouse writer in PurchasesController.'
        );
        $this->assertSame(
            self::RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($returns, '$product_warehouse->save();'),
            'MS1 must NOT move any legacy product_warehouse writer in PurchasesReturnController.'
        );

        $lookup = "product_warehouse = product_warehouse::where('deleted_at', '=', null)";
        $this->assertSame(11, substr_count($purchases, $lookup));
        $this->assertSame(10, substr_count($returns, $lookup));
    }

    public function test_writer_inventory_context_map_is_intact(): void
    {
        $contexts = [
            'PurchasesController' => [
                ['store', '+='],
                ['update', '-='],
                ['update', '+='],
                ['destroy', '-='],
                ['delete_by_selection', '-='],
                ['store_import_purchases', '+='],
            ],
            'PurchasesReturnController' => [
                ['store', '-='],
                ['update', '+='],
                ['update', '-='],
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
