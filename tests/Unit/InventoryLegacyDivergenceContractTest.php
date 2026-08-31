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

        // Lee el legado por (ALMACÉN, VARIANTE), no global ni por producto.
        $this->assertStringContainsString(
            "->groupBy('product_id', 'warehouse_id', DB::raw('COALESCE(product_variant_id, 0)'))",
            $src
        );
        $this->assertStringContainsString("->groupBy('s.product_id', 'il.warehouse_id', 's.variant_key')", $src);
        // Compara contra el agregado location-native de ubicaciones de ALMACÉN
        // (warehouse_id NOT NULL), nunca branch-native.
        $this->assertStringContainsString("->whereNotNull('il.warehouse_id')", $src);
        // Suma sólo el lado positivo por (almacén, variante) — sin compensación
        // entre almacenes NI entre variantes.
        $this->assertStringContainsString('foreach ($byVar as $vk => $legacyQty)', $src);
        $this->assertStringContainsString('max(0, round($legacyQty - $locQty, 3))', $src);
        // Expone la señal informativa.
        $this->assertStringContainsString("'legacy_pending' =>", $src);
        $this->assertStringContainsString("'legacy_pending_quantity' =>", $src);
        // company_available sigue derivándose SOLO de filas location-native.
        $this->assertStringContainsString(
            "'company_available' => round(\$rows->where('is_quarantine', false)->sum('available'), 3)",
            $src
        );
        $this->assertStringNotContainsString('$rows->isEmpty()', $src);
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
        $this->assertStringContainsString("'legacy_pending' => \$this->legacyPendingForLocation(\$location)", $src);

        // El catálogo operable sigue leyendo SOLO inventory_location_stocks.
        $this->assertStringContainsString(
            "InventoryLocationStock::where('inventory_location_id', \$location->id)",
            $src
        );
        $this->assertStringContainsString("->where('quantity', '>', 0)", $src);

        // legacy_pending es de sólo lectura, VARIANT-AWARE (nunca compensa
        // variantes), compara legado del almacén contra el AGREGADO de todas sus
        // ubicaciones activas, y distingue 'divergence' de 'other_location'.
        $this->assertStringContainsString('private function legacyPendingForLocation(', $src);
        $this->assertStringContainsString("->where('pw.warehouse_id', \$location->warehouse_id)", $src);
        $this->assertStringContainsString("->where('il.warehouse_id', \$location->warehouse_id)", $src);
        $this->assertStringContainsString("->groupBy('s.product_id', 's.variant_key')", $src);
        $this->assertStringContainsString("'product_variant_id' => \$variantKey > 0 ? \$variantKey : null", $src);
        $this->assertStringContainsString('$row->v_code ?: $row->p_code', $src);
        $this->assertStringContainsString("\$kind = 'divergence'", $src);
        $this->assertStringContainsString("\$kind = 'other_location'", $src);
        $this->assertStringContainsString("havingRaw('SUM(pw.qte) > 0')", $src);
    }

    public function test_transfer_form_explains_pending_instead_of_not_found(): void
    {
        $vue = $this->read('resources/src/views/app/pages/transfers/next/form.vue');

        $this->assertStringContainsString('legacyPending', $vue);
        $this->assertStringContainsString('d.legacy_pending', $vue);
        // Distingue divergencia real de "está en otra ubicación del almacén".
        $this->assertStringContainsString('pending.kind === "divergence"', $vue);
        $this->assertStringContainsString('pending.kind === "other_location"', $vue);
        $this->assertStringContainsString('Divergencia de inventario pendiente', $vue);
        $this->assertStringContainsString('Sin existencia en esta ubicación', $vue);
        $this->assertStringContainsString('pending.warehouse_location_quantity', $vue);
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

    public function test_reconcile_command_has_read_only_plan_flag_separate_from_apply(): void
    {
        $src = $this->read('app/Console/Commands/ProdexInventoryReconcile.php');

        $this->assertStringContainsString('--plan', $src);
        $this->assertStringContainsString('planIncremental($warehouseId)', $src);
        // --plan y --apply son mutuamente excluyentes.
        $this->assertStringContainsString("Usa --plan (sólo lectura) o --apply, no ambos.", $src);
    }

    public function test_whole_warehouse_backfill_refuses_non_empty_main_and_points_to_incremental(): void
    {
        $src = $this->read('app/Services/LegacyInventoryReconciliationService.php');

        $this->assertStringContainsString('public function planIncremental(', $src);
        $this->assertStringContainsString("'warehouse_has_location_stock' =>", $src);
        $this->assertStringContainsString("'needs_incremental' =>", $src);
        // La verdad de comparación es el AGREGADO del almacén, no una ubicación.
        $this->assertStringContainsString('private function warehouseLocationMap(int $warehouseId): array', $src);
        $this->assertStringContainsString('$delta = $this->decimal($legacyQty - $warehouseLocQty);', $src);
        // El backfill de almacén completo sólo opera desde almacén sin stock y
        // remite al plan incremental cuando ya hay stock en cualquier ubicación.
        $this->assertStringContainsString('planIncremental / prodex:inventory-reconcile --plan', $src);
        $this->assertStringContainsString('! empty($this->warehouseLocationMap($warehouseId))', $src);
        // delta negativo nunca se descuenta en automático.
        $this->assertStringContainsString("if (\$delta < 0) \$reasons[] = 'delta_negativo';", $src);
        $this->assertStringContainsString("\$reasons[] = 'lote_o_serie';", $src);
        $this->assertStringContainsString("\$reasons[] = 'reservado';", $src);
        $this->assertStringContainsString("\$reasons[] = 'transito_salida';", $src);
        // El plan expone dónde vive el stock antes de aplicar nada.
        $this->assertStringContainsString("'main_quantity' =>", $src);
        $this->assertStringContainsString("'other_locations_quantity' =>", $src);
        $this->assertStringContainsString("'warehouse_location_quantity' =>", $src);
        $this->assertStringContainsString("'target_inventory_location_id' =>", $src);
    }

    public function test_audit_separates_reconciled_from_transition_ready_and_single_target(): void
    {
        $src = $this->read('app/Services/LegacyInventoryReconciliationService.php');

        // is_reconciled = paridad cuantitativa; has_target_location = destino APTO;
        // transition_ready = ambas; target_holds_all_stock = single-target.
        $this->assertStringContainsString("'has_target_location' => \$target !== null", $src);
        $this->assertStringContainsString("'transition_ready' => \$isReconciled && \$target !== null", $src);
        $this->assertStringContainsString("'stock_outside_target_quantity' => \$stockOutsideTarget", $src);
        $this->assertStringContainsString("'target_holds_all_stock' => \$targetHoldsAllStock", $src);

        // Contrato de destino APTO: storage, no cuarentena.
        $this->assertStringContainsString('private function eligibleLegacyTargetLocation(Warehouse $warehouse): ?InventoryLocation', $src);
        $this->assertStringContainsString('if ($default->type !== InventoryLocation::TYPE_STORAGE) return null;', $src);
        $this->assertStringContainsString('if ($default->is_quarantine) return null;', $src);

        // planIncremental nunca dice ADD sin destino.
        $this->assertStringContainsString("if (\$target === null && \$delta > 0) \$reasons[] = 'sin_ubicacion_destino';", $src);
        $this->assertStringContainsString('$target = $this->eligibleLegacyTargetLocation($warehouse);', $src);
    }

    public function test_transition_service_is_warehouse_aggregate_aware_not_single_main(): void
    {
        $src = $this->read('app/Services/InventoryCompatibilityService.php');

        // shadow / read comparan contra el agregado del almacén, no sólo MAIN.
        $this->assertStringContainsString('public function warehouseAggregateQuantity(', $src);
        $this->assertStringContainsString('return $this->warehouseAggregateQuantity($warehouseId, $productId, $variantId);', $src);
        // enableMode exige destino apto y bloquea dual_write salvo single-target.
        $this->assertStringContainsString("! (\$audit['has_target_location'] ?? false)", $src);
        $this->assertStringContainsString("! (\$audit['target_holds_all_stock'] ?? false)", $src);
        $this->assertStringContainsString('dual_write requiere que TODO el inventario por ubicación del almacén esté en la ubicación destino', $src);
        // mirrorLegacySnapshot rehúsa si hay stock fuera de MAIN.
        $this->assertStringContainsString('abs($warehouseAggregate - $current) > 0.0005', $src);
        $this->assertStringContainsString('fuera de MAIN', $src);
    }
}
