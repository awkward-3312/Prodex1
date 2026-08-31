<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regresión: un producto con stock legado en `product_warehouse` pero sin fila
 * en el motor por ubicación (`inventory_location_stocks`) aparecía como
 * "0 disponible en la empresa" en "Existencias por ubicación" y como "Producto
 * no encontrado" al crear un traslado px-next, sin explicar que el almacén
 * simplemente no ha sido reconciliado todavía.
 *
 * Contrato (mismo estilo que TransferWorkflowAuditTest): las superficies de
 * lectura location-native deben distinguir "no migrado" de "sin stock", y la
 * reconciliación no debe migrar en automático productos con lote o serie/IMEI.
 */
class InventoryLegacyDivergenceContractTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 2).'/'.$rel;
        $this->assertFileExists($path, $rel.' debe existir');
        return (string) file_get_contents($path);
    }

    public function test_inventory_visibility_search_flags_legacy_pending_without_inflating_company_available(): void
    {
        $src = $this->read('app/Http/Controllers/InventoryVisibilityController.php');

        // Lee el legado.
        $this->assertStringContainsString("DB::table('product_warehouse')", $src);
        // Expone la señal informativa.
        $this->assertStringContainsString("'legacy_pending' =>", $src);
        $this->assertStringContainsString("'legacy_pending_quantity' =>", $src);
        // company_available sigue derivándose SOLO de filas location-native.
        $this->assertStringContainsString(
            "'company_available' => round(\$rows->where('is_quarantine', false)->sum('available'), 3)",
            $src
        );
        // legacy_pending sólo cuando NO hay ninguna fila por ubicación.
        $this->assertStringContainsString('$legacyQty > 0 && $rows->isEmpty()', $src);
    }

    public function test_inventory_visibility_widget_explains_pending_reconciliation(): void
    {
        $js = $this->read('resources/static/prodex-inventory-visibility.js');

        $this->assertStringContainsString('p.legacy_pending', $js);
        $this->assertStringContainsString('p.legacy_pending_quantity', $js);
        $this->assertStringContainsString('inventario heredado', $js);
        // No debe convertir el legado en stock operable.
        $this->assertStringNotContainsString('product_warehouse', $js);
    }

    public function test_transfer_location_products_returns_legacy_pending_but_never_as_catalogue(): void
    {
        $src = $this->read('app/Http/Controllers/TransferLocationController.php');

        // Nueva forma de respuesta { products, legacy_pending }.
        $this->assertStringContainsString("'products' => \$rows,", $src);
        $this->assertStringContainsString("'legacy_pending' => \$this->legacyPendingForLocation(\$location, \$stocks)", $src);

        // El catálogo operable sigue leyendo SOLO inventory_location_stocks.
        $this->assertStringContainsString(
            "InventoryLocationStock::where('inventory_location_id', \$location->id)",
            $src
        );
        $this->assertStringContainsString("->where('quantity', '>', 0)", $src);

        // legacy_pending es de sólo lectura: parte de product_warehouse del
        // almacén dueño, excluye lo que ya es location-native y nunca se fusiona
        // con $rows.
        $this->assertStringContainsString('private function legacyPendingForLocation(', $src);
        $this->assertStringContainsString("->where('pw.warehouse_id', \$location->warehouse_id)", $src);
        $this->assertStringContainsString('whereNotIn(\'pw.product_id\', $alreadyNative)', $src);
        $this->assertStringContainsString("havingRaw('SUM(pw.qte) > 0')", $src);
    }

    public function test_transfer_form_explains_pending_instead_of_not_found(): void
    {
        $vue = $this->read('resources/src/views/app/pages/transfers/next/form.vue');

        $this->assertStringContainsString('legacyPending', $vue);
        $this->assertStringContainsString('d.legacy_pending', $vue);
        $this->assertStringContainsString('inventario heredado del almacén de origen', $vue);
        // Sigue existiendo el fallback "Producto no encontrado" para el caso real.
        $this->assertStringContainsString('"Producto no encontrado."', $vue);
        // Compatibilidad con la forma antigua (array plano).
        $this->assertStringContainsString('Array.isArray(d) ? d :', $vue);
    }

    public function test_reconciliation_service_blocks_batch_and_serial_products(): void
    {
        $src = $this->read('app/Services/LegacyInventoryReconciliationService.php');

        $this->assertStringContainsString('batchOrSerialTrackedProducts', $src);
        $this->assertStringContainsString("'batch_or_serial_products' =>", $src);
        $this->assertStringContainsString("'is_backfillable' =>", $src);
        $this->assertStringContainsString("'batch_or_serial_stock' =>", $src);
        // Se apoya en Schema para no romper esquemas de test sin `products`.
        $this->assertStringContainsString("Schema::hasTable('products')", $src);
        $this->assertStringContainsString("Schema::hasColumn('products', 'is_batch_tracked')", $src);
        $this->assertStringContainsString("Schema::hasColumn('products', 'is_imei')", $src);
    }

    public function test_reconcile_command_surfaces_batch_serial_products_in_dry_run(): void
    {
        $src = $this->read('app/Console/Commands/ProdexInventoryReconcile.php');

        $this->assertStringContainsString("\$result['batch_or_serial_products']", $src);
        $this->assertStringContainsString('lote o serie/IMEI', $src);
    }
}
