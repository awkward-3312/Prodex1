<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  LOCATION-NATIVE PURCHASES — architecture contract
 * ============================================================================
 *
 *  MS0 (1e7289e) — fully-legacy baseline.
 *  MS1 (ce1fccf) — schema + models + engine prepared, controllers still legacy.
 *  MS2 (this)    — PurchasesController wired to the engine for
 *                  MODE_LOCATION_PRIMARY warehouses ONLY. Legacy writers stay.
 *                  PurchaseReturn + import + batch/serial are still legacy.
 *
 * Not fragile: pattern / method based, never line numbers.
 */
class PurchaseLocationNativeArchitectureTest extends TestCase
{
    private const MS1_MIGRATION =
        'database/migrations/tenant/2026_09_02_000000_add_inventory_location_to_purchases_and_returns.php';

    /** Legacy `$product_warehouse->save();` sites — UNCHANGED at MS2 (§11). */
    private const PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES = 11;
    private const RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES = 10;

    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Body of `(public|private|protected) function <name>(` up to the next function decl. */
    private function fn(string $src, string $name): string
    {
        if (! preg_match('/\n    (?:public|private|protected) function '.preg_quote($name, '/').'\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("method {$name}() not found");
        }
        $start = $m[0][1] + 1;
        $rest = substr($src, $start + 1);
        $end = preg_match('/\n    (?:public|private|protected) function /', $rest, $mm, PREG_OFFSET_CAPTURE)
            ? $mm[0][1] + 1 : strlen($rest);

        return substr($src, $start, $end + 1);
    }

    // =====================================================================
    // SCHEMA / MODELS  (MS1 — unchanged at MS2)
    // =====================================================================

    public function test_ms1_migration_adds_inventory_location_columns_to_both_documents(): void
    {
        $mig = $this->read(self::MS1_MIGRATION);
        $this->assertMatchesRegularExpression("/\\['purchases',\\s*'purchase_returns'\\]/", $mig);
        $this->assertStringContainsString("\$t->integer('inventory_location_id')->nullable()->index()", $mig);
        $this->assertStringContainsString("\$t->json('inventory_effect_snapshot')->nullable()", $mig);
        $this->assertStringContainsString('function down()', $mig);
        $this->assertStringNotContainsString("('branch_id')", $mig);
        $this->assertStringNotContainsString('->foreign(', $mig);
        $this->assertStringNotContainsString('DB::table(', $mig);
    }

    public function test_ms1_purchase_models_are_prepared(): void
    {
        foreach (['Purchase', 'PurchaseReturn'] as $model) {
            $src = $this->read("app/Models/{$model}.php");
            $this->assertStringContainsString("'inventory_location_id', 'inventory_effect_snapshot'", $src);
            $this->assertStringContainsString("'inventory_effect_snapshot' => 'array'", $src);
            $this->assertStringContainsString('function inventoryLocation()', $src);
        }
    }

    // =====================================================================
    // ENGINE  (MS1 — location-native pure)
    // =====================================================================

    public function test_engine_is_location_native_pure(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        $this->assertStringContainsString('class LocationAwarePurchaseStockService', $src);
        $this->assertStringContainsString('$this->inventory->increase(', $src);
        $this->assertStringContainsString('$this->inventory->decrease(', $src);
        $this->assertStringNotContainsString('product_warehouse', $src);
        $this->assertStringNotContainsString('mirrorLegacySnapshot', $src);
        foreach (['Purchase', 'PurchaseReversal', 'PurchaseReturn', 'PurchaseReturnReversal'] as $ref) {
            $this->assertStringContainsString("'{$ref}'", $src);
        }
    }

    public function test_mode_resolver_is_read_only(): void
    {
        $src = $this->read('app/Services/WarehouseInventoryModeResolver.php');
        $this->assertStringContainsString('class WarehouseInventoryModeResolver', $src);
        // read-only: a lookup, never firstOrCreate / save / create.
        $this->assertStringContainsString("InventoryTransitionState::where('warehouse_id', \$warehouseId)->first()", $src);
        $this->assertStringNotContainsString('firstOrCreate', $src);
        $this->assertStringNotContainsString('->save(', $src);
        $this->assertStringContainsString('assertHealthyLocationPrimary', $src);
        $this->assertStringContainsString("MODE_LOCATION_PRIMARY", $src);
        // MS2 hardening — transaction-time locking + boundary assertions.
        $this->assertStringContainsString('public function lockStates(', $src);
        $this->assertStringContainsString('->lockForUpdate()', $src);
        $this->assertStringContainsString('assertStateNotLocationPrimary', $src);
        $this->assertStringContainsString('assertStateHealthyLocationPrimary', $src);
    }

    // =====================================================================
    // MS2 · CONTROLLER — wired for MODE_LOCATION_PRIMARY only
    // =====================================================================

    public function test_ms2_purchases_controller_imports_the_engine_and_resolver(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $this->assertStringContainsString('use App\Services\LocationAwarePurchaseStockService;', $src);
        $this->assertStringContainsString('use App\Services\WarehouseInventoryModeResolver;', $src);
    }

    public function test_ms2_store_routes_by_location_primary_mode(): void
    {
        $store = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'store');
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary(', $store);
        $this->assertStringContainsString('return $this->storeLocationAware($request);', $store);
        // the legacy body is still right there after the guard.
        $this->assertStringContainsString('$product_warehouse->qte += ', $store);
    }

    public function test_ms2_update_and_destroy_route_by_persisted_identity(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach (['update', 'destroy'] as $name) {
            $body = $this->fn($src, $name);
            $this->assertStringContainsString('inventory_location_id !== null', $body, "{$name}() must branch on the stored identity");
            $this->assertStringContainsString($name.'LocationAware(', $body);
        }
    }

    public function test_ms2_bulk_delete_branches_per_row_identity(): void
    {
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'delete_by_selection');
        $this->assertStringContainsString('$current_Purchase->inventory_location_id !== null', $body);
        $this->assertStringContainsString('$this->reverseLocationNativePurchaseStock($current_Purchase);', $body);
        // legacy reversal still present for legacy rows.
        $this->assertStringContainsString('$product_warehouse->qte -= ', $body);
    }

    public function test_ms2_location_aware_private_methods_never_touch_product_warehouse(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach ([
            'storeLocationAware',
            'updateLocationAware',
            'destroyLocationAware',
            'reverseLocationNativePurchaseStock',
            'persistLocationAwarePurchaseDetails',
            'locationAwarePurchaseLines',
        ] as $name) {
            $body = $this->fn($src, $name);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$name}() must be location-native pure");
            $this->assertStringNotContainsString('BatchService', $body, "{$name}() must not run legacy batch writers");
            $this->assertStringNotContainsString('SerialNumberService', $body, "{$name}() must not run legacy serial writers");
        }
    }

    public function test_ms2_location_aware_store_uses_the_engine_and_writes_the_snapshot(): void
    {
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'storeLocationAware');
        // MS2 hardening — the boundary guard (which locks the transition state)
        // runs before any stock write.
        $this->assertStringContainsString('assertLocationNativePurchaseTransitionSafe($warehouseId, null);', $body);
        $this->assertStringContainsString("request()->validate(['inventory_location_id' => 'required|integer']);", $body);
        $this->assertStringContainsString('->validateAndLock(', $body);
        $this->assertStringContainsString("'inventory_effect_snapshot' => \$snapshot", $body);
        $this->assertStringContainsString('->applySnapshot($snapshot, $order->id);', $body);
        // pending guard: apply only when received.
        $this->assertStringContainsString("if (\$statut === 'received')", $body);
    }

    public function test_ms2_location_aware_update_reverses_old_then_applies_new(): void
    {
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'updateLocationAware');
        $this->assertStringContainsString('lockForUpdate()->firstOrFail();', $body);
        $this->assertStringContainsString('->assertSnapshotArtifactSafeAndLock($oldSnapshot);', $body);
        $this->assertStringContainsString('->reverseSnapshot($oldSnapshot, $locked->id);', $body);
        $this->assertStringContainsString('$oldRevision + 1', $body);
        $this->assertStringContainsString("if (\$newStatut === 'received')", $body);
        // MS2 hardening — old AND new warehouse must stay healthy location_primary.
        $this->assertStringContainsString('assertLocationNativePurchaseTransitionSafe((int) $locked->warehouse_id, $newWarehouseId);', $body);
    }

    public function test_ms2_transition_boundary_guards_fence_every_mutation_path(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $trait = $this->read('app/Http/Controllers/Concerns/GuardsPurchaseTransitionMode.php');

        // Both guards live in the shared trait, lock the transition state first
        // (deterministic asc), and PurchasesController uses it.
        $this->assertStringContainsString('use App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode;', $src);
        $this->assertStringContainsString('use GuardsPurchaseTransitionMode;', $src);
        $this->assertStringContainsString('function assertLegacyPurchaseTransitionSafe(', $trait);
        $this->assertStringContainsString('function assertLocationNativePurchaseTransitionSafe(', $trait);
        $this->assertStringContainsString('$resolver->lockStates(', $trait);

        // Legacy paths fenced: a location_primary warehouse can never take a
        // legacy product_warehouse mutation.
        foreach (['store', 'update', 'destroy', 'delete_by_selection'] as $method) {
            $body = $this->fn($src, $method);
            $this->assertStringContainsString(
                'assertLegacyPurchaseTransitionSafe(',
                $body,
                "legacy {$method}() is not fenced against a location_primary warehouse"
            );
        }

        // Location-native paths fenced: a demoted / unhealthy warehouse can
        // never take a location-native mutation.
        foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware', 'delete_by_selection'] as $method) {
            $body = $this->fn($src, $method);
            $this->assertStringContainsString(
                'assertLocationNativePurchaseTransitionSafe(',
                $body,
                "location-native {$method}() is not fenced against a non-primary warehouse"
            );
        }

        // The guards live inside the DB transaction of each mutating path
        // (checked structurally: the assertion text appears after the
        // \DB::transaction( opener within the same method body).
        foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware'] as $method) {
            $body = $this->fn($src, $method);
            $txPos = strpos($body, '\DB::transaction(');
            $guardPos = strpos($body, 'assertLocationNativePurchaseTransitionSafe(');
            $this->assertNotFalse($txPos);
            $this->assertNotFalse($guardPos);
            $this->assertGreaterThan($txPos, $guardPos, "{$method}() guard must run inside the transaction");
        }
    }

    // =====================================================================
    // MS3 · PURCHASE RETURNS location-native
    // =====================================================================

    public function test_ms3_purchase_returns_controller_is_wired_to_the_engine(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');

        $this->assertStringContainsString('use App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode;', $src);
        $this->assertStringContainsString('use GuardsPurchaseTransitionMode;', $src);
        $this->assertStringContainsString('use App\Services\LocationAwarePurchaseStockService;', $src);
        $this->assertStringContainsString('use App\Services\WarehouseInventoryModeResolver;', $src);
        $this->assertStringContainsString('LocationAwarePurchaseStockService::DOC_PURCHASE_RETURN', $src);

        // store routes by mode; update/destroy/bulk by persisted identity.
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary(', $this->fn($src, 'store'));
        foreach (['update', 'destroy'] as $m) {
            $this->assertStringContainsString('inventory_location_id !== null', $this->fn($src, $m));
            $this->assertStringContainsString($m.'LocationAware(', $this->fn($src, $m));
        }
        $this->assertStringContainsString('$current_PurchaseReturn->inventory_location_id !== null', $this->fn($src, 'delete_by_selection'));

        // legacy writers still present (unchanged count) + still fenced.
        $this->assertSame(
            self::RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($src, '$product_warehouse->save();'),
            'MS3 adds an ALTERNATIVE path; it must not remove any legacy return writer.'
        );
        foreach (['store', 'update', 'destroy', 'delete_by_selection'] as $m) {
            $this->assertStringContainsString('assertLegacyPurchaseTransitionSafe(', $this->fn($src, $m));
        }
        foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware', 'delete_by_selection'] as $m) {
            $this->assertStringContainsString('assertLocationNativePurchaseTransitionSafe(', $this->fn($src, $m));
        }
    }

    public function test_ms3_native_return_methods_are_location_native_pure(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        foreach ([
            'storeLocationAware',
            'updateLocationAware',
            'destroyLocationAware',
            'reverseLocationNativePurchaseReturnStock',
        ] as $name) {
            $body = $this->fn($src, $name);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$name}() must be location-native pure");
            $this->assertStringNotContainsString('BatchService', $body);
            $this->assertStringNotContainsString('SerialNumberService', $body);
        }

        // completed-status semantics preserved (NOT unified with 'received').
        $this->assertStringContainsString("=== 'completed'", $this->fn($src, 'storeLocationAware'));
        $this->assertStringContainsString("statut !== 'completed'", $this->fn($src, 'reverseLocationNativePurchaseReturnStock'));
    }

    public function test_ms3_return_endpoints_and_scope(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("purchase_returns_inventory_locations/{warehouse_id}', 'PurchasesReturnController@inventoryLocationsForWarehouse'", $routes);
        $this->assertStringContainsString("purchase_returns_location_catalog/{location_id}', 'PurchasesReturnController@inventoryLocationCatalog'", $routes);

        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        // a return is OUTBOUND => operating scope, NOT receivingLocationIds.
        $ep = $this->fn($src, 'inventoryLocationsForWarehouse');
        $this->assertStringContainsString('allowedLocationIds', $ep);
        $this->assertStringNotContainsString('receivingLocationIds', $ep);
        // per-location stock read uses the shared catalog service (not product_warehouse).
        $cat = $this->fn($src, 'inventoryLocationCatalog');
        $this->assertStringContainsString('LocationCatalogReadService', $cat);
        $this->assertStringNotContainsString('product_warehouse', $cat);
    }

    public function test_ms3_return_frontend_sends_inventory_location_id_and_reads_location_stock(): void
    {
        foreach ([
            'resources/src/views/app/pages/purchase_return/create_purchase_return.vue',
            'resources/src/views/app/pages/purchase_return/edit_purchase_return.vue',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString('purchase_returns_inventory_locations/', $src);
            $this->assertStringContainsString('purchase_returns_location_catalog/', $src, "$rel must read stock per location");
            $this->assertStringContainsString('inventory_location_id', $src);
            $this->assertStringContainsString('requires_inventory_location', $src);
            // location-primary path must NOT read product_warehouse.qte as physical stock.
            $this->assertStringContainsString('available_quantity', $src);
        }
    }

    public function test_ms3_l4_legacy_bug_stays_characterized_not_fixed(): void
    {
        // The legacy PurchasesReturnController::update still does NOT persist a
        // changed warehouse_id (L4). The golden master keeps characterizing it.
        $legacyUpdate = $this->fn($this->read('app/Http/Controllers/PurchasesReturnController.php'), 'update');
        // legacy branch: header update() call must not include warehouse_id.
        $this->assertStringContainsString("\$current_PurchaseReturn->update([", $legacyUpdate);
        $this->assertStringNotContainsString("'warehouse_id' => \$request", $legacyUpdate);

        $gm = $this->read('tests/Feature/PurchaseReturnsLegacyGoldenMasterTest.php');
        $this->assertStringContainsString('test_update_change_warehouse_moves_stock_but_record_keeps_old_warehouse', $gm);
        $this->assertStringContainsString('// NOT updated', $gm);

        // the NATIVE branch DOES persist the new identity (its snapshot needs it).
        $nativeUpdate = $this->fn($this->read('app/Http/Controllers/PurchasesReturnController.php'), 'updateLocationAware');
        $this->assertStringContainsString("'warehouse_id' => \$newWarehouseId", $nativeUpdate);
        $this->assertStringContainsString("'inventory_location_id' => \$newLocationId", $nativeUpdate);
    }

    public function test_import_purchases_is_still_legacy_and_marked_pending_ms4(): void
    {
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'store_import_purchases');
        $this->assertStringNotContainsString('LocationAwarePurchaseStockService', $body);
        $this->assertStringNotContainsString('inventory_location_id', $body);
        $this->assertStringContainsString('$product_warehouse->qte += ', $body, 'import still uses the legacy writer');
        // explicit "not production-ready" fence for MS2.
        $doc = $this->read('tests/Feature/PurchasesLocationNativeTest.php');
        $this->assertStringContainsString('NOT production-ready', $doc);
        $this->assertStringContainsString('store_import_purchases and PurchaseReturn', $doc);
    }

    // =====================================================================
    // WRITER INVENTORY — legacy sites intact
    // =====================================================================

    public function test_legacy_product_warehouse_writer_counts_are_unchanged(): void
    {
        $purchases = $this->read('app/Http/Controllers/PurchasesController.php');
        $returns = $this->read('app/Http/Controllers/PurchasesReturnController.php');

        $this->assertSame(
            self::PURCHASES_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($purchases, '$product_warehouse->save();'),
            'MS2 adds an ALTERNATIVE path; it must not remove/move any legacy writer.'
        );
        $this->assertSame(
            self::RETURNS_CONTROLLER_LEGACY_PW_SAVE_SITES,
            substr_count($returns, '$product_warehouse->save();')
        );

        $lookup = "product_warehouse = product_warehouse::where('deleted_at', '=', null)";
        $this->assertSame(11, substr_count($purchases, $lookup));
        $this->assertSame(10, substr_count($returns, $lookup));
    }

    public function test_legacy_write_context_map_is_intact(): void
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

        foreach ($contexts as $controller => $entries) {
            $src = $this->read("app/Http/Controllers/{$controller}.php");
            foreach ($entries as [$method, $dir]) {
                $body = $this->fn($src, $method);
                $this->assertStringContainsString(
                    "\$product_warehouse->qte {$dir} ",
                    $body,
                    "{$controller}::{$method}() no longer performs a legacy '{$dir}' product_warehouse write."
                );
            }
        }
    }

    // =====================================================================
    // MS2 · ENDPOINT + FRONTEND
    // =====================================================================

    public function test_ms2_inventory_locations_endpoint_exists(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString(
            "Route::get('purchases_inventory_locations/{warehouse_id}', 'PurchasesController@inventoryLocationsForWarehouse');",
            $routes
        );
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'inventoryLocationsForWarehouse');
        $this->assertStringContainsString('inventoryLocationContextPayload(', $body);
        $this->assertStringContainsString('receivingLocationIds', $body); // purchase = receiving scope

        // the payload contract lives in the shared trait.
        $trait = $this->read('app/Http/Controllers/Concerns/GuardsPurchaseTransitionMode.php');
        foreach (['transition_mode', 'transition_status', 'requires_inventory_location', 'blocked', 'default_inventory_location_id', 'is_quarantine'] as $key) {
            $this->assertStringContainsString("'{$key}'", $trait);
        }
    }

    public function test_ms2_create_and_edit_send_inventory_location_id(): void
    {
        foreach ([
            'resources/src/views/app/pages/purchases/create_purchase.vue',
            'resources/src/views/app/pages/purchases/edit_purchase.vue',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString('purchases_inventory_locations/', $src, "$rel must load the endpoint");
            $this->assertStringContainsString('inventory_location_id', $src, "$rel must send inventory_location_id");
            $this->assertStringContainsString('requires_inventory_location', $src);
        }
    }
}
