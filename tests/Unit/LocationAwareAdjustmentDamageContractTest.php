<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contrato #81 — Ajustes / Daños location-aware.
 *
 *  A24 — el flujo location-aware NO escribe product_warehouse.
 *  A25 — el frontend manda inventory_location_id y bloquea el submit sin ella.
 *  A20/A21 — la rama legacy (inventory_location_id NULL) queda intacta.
 */
class LocationAwareAdjustmentDamageContractTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Extrae SÓLO el bloque de métodos #81 del controller (hasta getNumberOrder). */
    private function locationAwareBlock(string $controllerSrc): string
    {
        $start = strpos($controllerSrc, '#81 · ');
        $this->assertNotFalse($start, 'no se encontró el bloque #81 en el controller');
        $endMarkers = ['public function getNumberOrder(', 'Reference Number of Adjustement', '-------------Show Form Create Damage'];
        $end = strlen($controllerSrc);
        foreach ($endMarkers as $m) {
            $p = strpos($controllerSrc, $m, $start);
            if ($p !== false) $end = min($end, $p);
        }

        return substr($controllerSrc, $start, $end - $start);
    }

    public function test_a24_location_aware_flow_never_writes_product_warehouse(): void
    {
        foreach (['adjustment', 'damage'] as $kind) {
            $engine = $this->read('app/Services/LocationAwareStockDocumentService.php');
            $facade = $this->read('app/Services/LocationAware'.ucfirst($kind).'Service.php');

            // El motor y las fachadas SÓLO mutan stock vía InventoryService.
            $this->assertStringContainsString('app(InventoryService::class)', $engine);
            $this->assertStringContainsString('->increase(', $engine);
            $this->assertStringContainsString('->decrease(', $engine);
            // PROHIBIDO en el flujo nuevo (spec O) — ninguna ESCRITURA a legacy:
            $this->assertStringNotContainsString("DB::table('product_warehouse')", $engine);
            $this->assertStringNotContainsString('product_warehouse::', $engine);
            $this->assertStringNotContainsString('->qte ', $engine);
            $this->assertStringNotContainsString('->qte=', $engine);
            $this->assertStringNotContainsString('adjustTo(', $engine);
            $this->assertStringNotContainsString('mirrorLegacySnapshot', $engine);
            $this->assertStringNotContainsString("DB::table('product_warehouse')", $facade);
        }

        // Los métodos *LocationAware del controller no tocan product_warehouse
        // ni BatchService.
        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $block = $this->locationAwareBlock($this->read("app/Http/Controllers/$ctrl.php"));
            $this->assertStringNotContainsString('product_warehouse::', $block);
            $this->assertStringNotContainsString("DB::table('product_warehouse')", $block);
            $this->assertStringNotContainsString('$batchService', $block);
            $this->assertStringNotContainsString('->qte ', $block);
            $this->assertStringContainsString('function storeLocationAware(', $block);
            $this->assertStringContainsString('function updateLocationAware(', $block);
            $this->assertStringContainsString('function destroyLocationAware(', $block);
            // el flujo nuevo usa las fachadas location-aware.
            $this->assertStringContainsString('LocationAware', $block);
        }
    }

    public function test_a24b_provenance_refs_are_native_not_reconciliation(): void
    {
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        // Adjustment / Damage / *Reversal NO están en RECONCILIATION_REFS.
        foreach (['Adjustment', 'AdjustmentReversal', 'Damage', 'DamageReversal'] as $ref) {
            // aparecen como constantes del motor location-aware…
            $this->assertStringContainsString("'$ref'", $this->read('app/Services/LocationAwareStockDocumentService.php'));
            // …pero NUNCA se añaden a RECONCILIATION_REFS / DUAL_WRITE_MIRROR_REFS.
        }
        $reconBlock = substr($prov, strpos($prov, 'RECONCILIATION_REFS = ['), 200);
        $this->assertStringNotContainsString("'Adjustment'", $reconBlock);
        $this->assertStringNotContainsString("'Damage'", $reconBlock);
    }

    public function test_a25_frontend_sends_inventory_location_id_and_guards_submit(): void
    {
        $forms = [
            'resources/src/views/app/pages/adjustment/Create_Adjustment.vue',
            'resources/src/views/app/pages/adjustment/Edit_Adjustment.vue',
            'resources/src/views/app/pages/damage/Create_Damage.vue',
            'resources/src/views/app/pages/damage/Edit_Damage.vue',
        ];
        foreach ($forms as $form) {
            $src = $this->read($form);
            // v-model del select de ubicación.
            $this->assertMatchesRegularExpression('/v-model="(adjustment|damage)\.inventory_location_id"/', $src, "$form: falta v-model de inventory_location_id");
            // select obligatorio.
            $this->assertStringContainsString("name=\"inventory_location\" :rules=\"{ required: true }\"", $src, "$form: el select de ubicación no es obligatorio");
            // no se puede cambiar la ubicación con detalles presentes.
            $this->assertStringContainsString(':disabled="details.length > 0', $src, "$form: falta el guard :disabled con details");
            // el submit envía inventory_location_id.
            $this->assertMatchesRegularExpression('/inventory_location_id:\s*(this\.(adjustment|damage)\.inventory_location_id|this\.record_is_location_aware)/', $src, "$form: el submit no envía inventory_location_id");
        }

        // Create_* cargan las ubicaciones activas del almacén al elegirlo, desde
        // un endpoint scoped al warehouse.
        foreach (['Create_Adjustment' => 'adjustments', 'Create_Damage' => 'damages'] as $file => $prefix) {
            $create = $this->read("resources/src/views/app/pages/".(str_contains($file, 'Adjustment') ? 'adjustment' : 'damage')."/$file.vue");
            $this->assertStringContainsString("{$prefix}_inventory_locations/", $create);
            $this->assertStringContainsString('Load_Inventory_Locations', $create);
        }
    }

    public function test_a25b_backend_endpoint_returns_active_locations_of_warehouse(): void
    {
        $routes = $this->read('routes/tenant_api.php');
        $this->assertStringContainsString("adjustments_inventory_locations/{warehouse_id}", $routes);
        $this->assertStringContainsString("damages_inventory_locations/{warehouse_id}", $routes);

        foreach (['AdjustmentController', 'DamageController'] as $ctrl) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            $this->assertStringContainsString('function inventoryLocationsForWarehouse(', $src);
            $this->assertStringContainsString("->where('warehouse_id', \$warehouseId)", $src);
            $this->assertStringContainsString("->where('is_active', 1)", $src);
            $this->assertStringContainsString("InventoryLocation::whereNull('deleted_at')", $src);
            // cuarentena es una ubicación física legítima => se incluye.
            $this->assertStringContainsString("'is_quarantine'", $src);
            // la default sólo se PRESELECCIONA si es apta.
            $this->assertStringContainsString('default_inventory_location_id', $src);
        }
    }

    public function test_a20_a21_legacy_records_keep_historical_path(): void
    {
        foreach (['AdjustmentController' => 'adjustment', 'DamageController' => 'damage'] as $ctrl => $var) {
            $src = $this->read("app/Http/Controllers/$ctrl.php");
            // store: sólo va al flujo nuevo si el request trae inventory_location_id.
            $this->assertStringContainsString("if (\$request->filled('inventory_location_id')) {", $src);
            // update/destroy: el discriminador es el VALOR ALMACENADO, no el request.
            $this->assertStringContainsString("current_{$var}->inventory_location_id !== null", $src);
            $this->assertStringContainsString("preload->inventory_location_id !== null", $src);
            // la rama legacy sigue tocando product_warehouse (no se removió).
            $this->assertStringContainsString('product_warehouse', $src);
        }

        // El modelo distingue: NULL = legacy, NOT NULL = location-aware.
        $this->assertStringContainsString("'inventory_location_id' => 'integer'", $this->read('app/Models/Adjustment.php'));
        $this->assertStringContainsString("'inventory_location_id' => 'integer'", $this->read('app/Models/Damage.php'));

        // Migración nullable, sin backfill de registros viejos.
        $mig = $this->read('database/migrations/tenant/2026_08_31_000000_add_inventory_location_to_adjustments_and_damages.php');
        $this->assertStringContainsString("->integer('inventory_location_id')->nullable()", $mig);
        $this->assertStringNotContainsString('update(', $mig);
        $this->assertStringNotContainsString('backfill', strtolower($mig));

        // schema-health exige la columna.
        $health = $this->read('app/Services/TenantSchemaHealthService.php');
        $this->assertStringContainsString("requireColumns(\$schema, \$missing, 'adjustments', ['inventory_location_id'])", $health);
        $this->assertStringContainsString("requireColumns(\$schema, \$missing, 'damages', ['inventory_location_id'])", $health);
        $this->assertStringContainsString('2026_08_31_000000_add_inventory_location_to_adjustments_and_damages.php', $health);
    }
}
