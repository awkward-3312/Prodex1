<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contrato #81 (iteración 2) — Ajustes / Daños location-aware.
 */
class LocationAwareAdjustmentDamageContractTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    // ===== BLOCKER 1 — no se crean documentos legacy nuevos ================

    public function test_b1_b2_store_requires_inventory_location_id(): void
    {
        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            $storeStart = strpos($src, 'public function store(');
            $this->assertNotFalse($storeStart);
            $storeBody = substr($src, $storeStart, 900);
            // store exige inventory_location_id: sin ella => ValidationException (422).
            $this->assertStringContainsString("if (! \$request->filled('inventory_location_id')) {", $storeBody);
            $this->assertStringContainsString('ValidationException::withMessages([', $storeBody);
            // y SIEMPRE va al flujo location-aware.
            $this->assertStringContainsString('return $this->storeLocationAware($request);', $storeBody);
            // ya NO existe la bifurcación "si trae el campo".
            $this->assertStringNotContainsString("if (\$request->filled('inventory_location_id')) {\n            return \$this->storeLocationAware", $storeBody);
        }
    }

    // ===== A20/A21 — sólo update/destroy de históricos NULL siguen legacy ==

    public function test_a20_a21_legacy_records_keep_historical_update_destroy(): void
    {
        foreach (['AdjustmentController' => 'adjustment', 'DamageController' => 'damage'] as $ctrl => $var) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            // update/destroy: discriminador = valor ALMACENADO.
            $this->assertStringContainsString("current_{$var}->inventory_location_id !== null", $src);
            $this->assertStringContainsString('preload->inventory_location_id !== null', $src);
            // la rama legacy de update/destroy conserva product_warehouse.
            $this->assertStringContainsString('product_warehouse', $src);
            // NO se afirma ningún fallback legacy en store.
            $this->assertStringNotContainsString('storeLegacy(', $src);
        }
    }

    // ===== BLOCKER 2 — reversa por snapshot histórico =====================

    public function test_b4_b5_b6_reversal_uses_effect_snapshot_not_current_composition(): void
    {
        $engine = $this->read('app/Services/LocationAwareStockDocumentService.php');
        // snapshot EXPANDIDO: buildAdjustmentSnapshot / buildDamageSnapshot devuelven
        // efectos por componente + combo_parent.
        $this->assertStringContainsString('public function buildAdjustmentSnapshot(', $engine);
        $this->assertStringContainsString('public function buildDamageSnapshot(', $engine);
        $this->assertStringContainsString("'combo_component'", $engine);
        $this->assertStringContainsString("'combo_parent'", $engine);
        // reverseSnapshot niega deltas del snapshot dado — NO recalcula.
        $this->assertStringContainsString('public function reverseSnapshot(', $engine);
        $this->assertStringContainsString("\$reverse ? -\$e['delta'] : \$e['delta']", $engine);
        // FAIL CLOSED si no hay snapshot.
        $this->assertStringContainsString('public function normalizeSnapshot(', $engine);
        $this->assertStringContainsString('no se revierte reconstruyendo la composición actual', $engine);
        // applyEffects NO consulta CombinedProduct.
        $applyStart = strpos($engine, 'private function applyEffects(');
        $applyBody = substr($engine, $applyStart, 2500);
        $this->assertStringNotContainsString('CombinedProduct', $applyBody);
        $this->assertStringNotContainsString('combined_products', $applyBody);

        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            // create: persiste el snapshot y aplica ESE.
            $this->assertStringContainsString("->update(['inventory_effect_snapshot' => \$snapshot]);", $src);
            $this->assertStringContainsString('$svc->applySnapshot($snapshot,', $src);
            // update/destroy: normalizeSnapshot del ALMACENADO + reverseSnapshot.
            $this->assertStringContainsString('$svc->normalizeSnapshot($locked->inventory_effect_snapshot)', $src);
            $this->assertStringContainsString('$svc->reverseSnapshot(', $src);
        }

        // migración: columnas nullable json, sin backfill.
        $mig = $this->read('database/migrations/tenant/2026_08_31_000000_add_inventory_location_to_adjustments_and_damages.php');
        $this->assertStringContainsString("->json('inventory_effect_snapshot')->nullable()", $mig);
        $this->assertStringContainsString("->integer('inventory_location_id')->nullable()", $mig);
        $this->assertStringNotContainsString('->update(', $mig);
        // modelos: cast array.
        $this->assertStringContainsString("'inventory_effect_snapshot' => 'array'", $this->read('app/Models/Adjustment.php'));
        $this->assertStringContainsString("'inventory_effect_snapshot' => 'array'", $this->read('app/Models/Damage.php'));
    }

    // ===== BLOCKER 3 — catálogo por ubicación =============================

    public function test_b3_catalog_endpoint_is_location_scoped(): void
    {
        $cat = $this->read('app/Services/LocationCatalogReadService.php');
        $this->assertStringContainsString('public function forLocation(int $locationId): array', $cat);
        $this->assertStringContainsString("'available_quantity' => round(\$physical - \$reserved, 3)", $cat);
        $this->assertStringContainsString("'stock_source' => 'inventory_location'", $cat);
        // productos a 0 stock también aparecen (no stockOnly).
        $this->assertStringNotContainsString('stockOnly', $cat);
        $this->assertStringNotContainsString("->where('quantity', '>', 0)", $cat);
        // NO reutiliza PosLocationCatalogController ni filtra por is_sellable.
        $this->assertStringNotContainsString('app(PosLocation', $cat);
        $this->assertStringNotContainsString('PosLocationCatalogController::class', $cat);
        $this->assertStringNotContainsString("->where('is_sellable'", $cat);

        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("adjustments_location_catalog/{location_id}", $routes);
        $this->assertStringContainsString("damages_location_catalog/{location_id}", $routes);

        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            $this->assertStringContainsString('function inventoryLocationCatalog(', $src);
            $this->assertStringContainsString('LocationCatalogReadService::class)->forLocation(', $src);
        }
    }

    // ===== BLOCKER 4 — validación + plan bajo la misma transacción / locks =

    public function test_b10_validation_and_locks_inside_transaction_deterministic_order(): void
    {
        $engine = $this->read('app/Services/LocationAwareStockDocumentService.php');
        $this->assertStringContainsString('public function validateAndLock(', $engine);
        $this->assertStringContainsString('$this->assertInTransaction();', $engine);
        // orden: Warehouse -> InventoryLocation -> Products ASC -> CombinedProduct -> ProductVariants ASC
        $posWh = strpos($engine, "DB::table('warehouses')->where('id', \$warehouseId)->whereNull('deleted_at')");
        $posLoc = strpos($engine, 'InventoryLocation::whereKey($locationId)->lockForUpdate()');
        $posProd = strpos($engine, "DB::table('products')->whereIn('id', \$allProductIds)->orderBy('id')->lockForUpdate()");
        $posCombo = strpos($engine, "DB::table('combined_products')->whereIn('product_id', \$comboIds)");
        $posVar = strpos($engine, "DB::table('product_variants')->whereIn('id', \$ids)->orderBy('id')->lockForUpdate()");
        foreach (['posWh' => $posWh, 'posLoc' => $posLoc, 'posProd' => $posProd, 'posCombo' => $posCombo, 'posVar' => $posVar] as $n => $v) {
            $this->assertNotFalse($v, "no se encontró: $n");
        }
        $this->assertLessThan($posLoc, $posWh);
        $this->assertLessThan($posProd, $posLoc);
        $this->assertLessThan($posCombo, $posProd);
        $this->assertLessThan($posVar, $posCombo);
        // los flags se leen de filas bloqueadas ($product del set locked).
        $this->assertStringContainsString('(int) ($p->is_batch_tracked ?? 0) === 1', $engine);
        $this->assertStringContainsString('(int) ($p->is_imei ?? 0) === 1', $engine);

        // en el controller: validateAndLock se invoca DENTRO de \DB::transaction.
        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            $storePos = strpos($src, 'private function storeLocationAware(');
            $seg = substr($src, $storePos, 1600);
            $txPos = strpos($seg, '\DB::transaction(');
            $valPos = strpos($seg, '$svc->validateAndLock(');
            $this->assertNotFalse($txPos);
            $this->assertNotFalse($valPos);
            $this->assertLessThan($valPos, $txPos, "$ctrl: validateAndLock debe estar dentro de la transacción");
        }
    }

    // ===== BLOCKER 5 — concurrencia del documento ========================

    public function test_b11_update_destroy_lock_document_inside_transaction(): void
    {
        foreach (['AdjustmentController' => 'Adjustment', 'DamageController' => 'Damage'] as $ctrl => $model) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            foreach (['updateLocationAware', 'destroyLocationAware'] as $method) {
                $pos = strpos($src, "private function $method(");
                $body = substr($src, $pos, 2200);
                $this->assertStringContainsString("\$locked = $model::whereKey(\$current->id)->lockForUpdate()->firstOrFail();", $body, "$ctrl::$method");
                // los details también se bloquean antes de revertir.
                $this->assertMatchesRegularExpression('/Detail::where\([^)]+\)->lockForUpdate\(\)->get\(\);/', $body, "$ctrl::$method: lock de details");
            }
        }
    }

    // ===== BLOCKER 6 — permiso del endpoint create OR update =============

    public function test_b12_location_endpoints_allow_create_or_update(): void
    {
        foreach (['AdjustmentController' => 'Adjustment', 'DamageController' => 'Damage'] as $ctrl => $model) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            $this->assertStringContainsString('private function authorizeLocationRead(Request $request): void', $src);
            $this->assertStringContainsString("Gate::forUser(\$u)->allows('create', $model::class) || Gate::forUser(\$u)->allows('update', $model::class)", $src);
            // ambos endpoints usan authorizeLocationRead (no authorizeForUser 'create').
            foreach (['inventoryLocationsForWarehouse', 'inventoryLocationCatalog'] as $m) {
                $pos = strpos($src, "function $m(");
                $body = substr($src, $pos, 400);
                $this->assertStringContainsString('$this->authorizeLocationRead($request);', $body, "$ctrl::$m");
            }
            // el warehouse scope NO se debilita.
            $this->assertStringContainsString('$this->assertWarehouseAccess(', $src);
        }
    }

    // ===== A24 — el flujo nuevo no escribe product_warehouse =============

    public function test_a24_location_aware_flow_never_writes_product_warehouse(): void
    {
        $engine = $this->read('app/Services/LocationAwareStockDocumentService.php');
        $this->assertStringContainsString('app(InventoryService::class)', $engine);
        $this->assertStringContainsString('->increase(', $engine);
        $this->assertStringContainsString('->decrease(', $engine);
        $this->assertStringNotContainsString("DB::table('product_warehouse')", $engine);
        $this->assertStringNotContainsString('product_warehouse::', $engine);
        $this->assertStringNotContainsString('->adjustTo(', $engine);
        $this->assertStringNotContainsString('mirrorLegacySnapshot', $engine);

        // los métodos *LocationAware del controller tampoco.
        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware'] as $m) {
                $pos = strpos($src, "private function $m(");
                $end = strpos($src, "\n    private function ", $pos + 10);
                if ($end === false) $end = strpos($src, "\n    public function ", $pos + 10);
                $body = substr($src, $pos, ($end ?: strlen($src)) - $pos);
                $this->assertStringNotContainsString('product_warehouse::', $body, "$ctrl::$m");
                $this->assertStringNotContainsString("DB::table('product_warehouse')", $body, "$ctrl::$m");
                $this->assertStringNotContainsString('$batchService', $body, "$ctrl::$m");
            }
        }
    }

    public function test_a24b_provenance_refs_are_native_not_reconciliation(): void
    {
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        $reconBlock = substr($prov, strpos($prov, 'RECONCILIATION_REFS = ['), 220);
        foreach (["'Adjustment'", "'Damage'", "'AdjustmentReversal'", "'DamageReversal'"] as $ref) {
            $this->assertStringNotContainsString($ref, $reconBlock);
        }
    }

    // ===== A25 — frontend: submit + catálogo POR UBICACIÓN ================

    public function test_a25_frontend_uses_location_catalog_not_warehouse_aggregate(): void
    {
        $forms = [
            'resources/src/views/app/pages/adjustment/Create_Adjustment.vue' => 'adjustment',
            'resources/src/views/app/pages/adjustment/Edit_Adjustment.vue' => 'adjustment',
            'resources/src/views/app/pages/damage/Create_Damage.vue' => 'damage',
            'resources/src/views/app/pages/damage/Edit_Damage.vue' => 'damage',
        ];
        foreach ($forms as $form => $var) {
            $src = $this->read($form);
            $this->assertMatchesRegularExpression('/v-model="'.$var.'\.inventory_location_id"/', $src, "$form: v-model ubicación");
            $this->assertStringContainsString('name="inventory_location" :rules="{ required: true }"', $src, "$form: select obligatorio");
            $this->assertStringContainsString(':disabled="details.length > 0', $src, "$form: guard :disabled");
            $this->assertMatchesRegularExpression('/inventory_location_id:\s*(this\.'.$var.'\.inventory_location_id|this\.record_is_location_aware)/', $src, "$form: submit envía inventory_location_id");
        }

        // Create location-aware: catálogo desde inventory_location_id, NO desde
        // Get_Products_By_Warehouse.
        foreach (['adjustment/Create_Adjustment' => 'adjustments', 'damage/Create_Damage' => 'damages'] as $file => $prefix) {
            $src = $this->read("resources/src/views/app/pages/$file.vue");
            $this->assertStringContainsString("{$prefix}_location_catalog/", $src, "$file: usa el endpoint de catálogo por ubicación");
            $this->assertStringContainsString('Load_Location_Catalog', $src, "$file: método de carga de catálogo por ubicación");
            // al elegir/cambiar ubicación se recarga el catálogo.
            $this->assertMatchesRegularExpression('/Selected_Inventory_Location\s*\([^)]*\)\s*\{[^}]*Load_Location_Catalog/s', $src, "$file: cambiar ubicación recarga el catálogo");
            // CurrentStock del nuevo modo NO viene del agregado de almacén.
            $this->assertStringContainsString('available_quantity', $src, "$file: CurrentStock = available de la ubicación");
        }
    }
}
