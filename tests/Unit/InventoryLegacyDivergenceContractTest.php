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

    public function test_inventory_visibility_search_uses_provenance_not_snapshot_delta(): void
    {
        $src = $this->read('app/Http/Controllers/InventoryVisibilityController.php');

        // La señal viene del auditor por PROVENANCE, no de legacy - location.
        $this->assertStringContainsString('InventoryProvenanceAuditService::class)', $src);
        $this->assertStringContainsString('->summaryByProduct($productIds)', $src);
        $this->assertStringContainsString("'legacy_pending_quantity' => \$legacyPendingQty", $src);
        // snapshot_drift se expone SOLO como diagnóstico, nunca como pendiente.
        $this->assertStringContainsString("'snapshot_drift' => round((float) \$prov['snapshot_drift'], 3)", $src);
        $this->assertStringNotContainsString('max(0, round($legacyQty - $locQty, 3))', $src);
        // company_available sigue derivándose SOLO de filas location-native.
        $this->assertStringContainsString(
            "'company_available' => round(\$rows->where('is_quarantine', false)->sum('available'), 3)",
            $src
        );
    }

    public function test_inventory_visibility_widget_explains_pending_by_provenance(): void
    {
        $js = $this->read('resources/static/prodex-inventory-visibility.js');

        $this->assertStringContainsString('p.legacy_pending', $js);
        $this->assertStringContainsString('operación legacy posterior al último baseline', $js);
        $this->assertStringContainsString('p.needs_review', $js);
        // No debe convertir el legado en stock operable ni hablar de "drift".
        $this->assertStringNotContainsString('product_warehouse', $js);
    }

    public function test_transfer_location_products_signal_is_provenance_based(): void
    {
        $src = $this->read('app/Http/Controllers/TransferLocationController.php');

        $this->assertStringContainsString("'products' => \$rows,", $src);
        $this->assertStringContainsString("'legacy_pending' => \$this->legacyPendingForLocation(\$location)", $src);

        // El catálogo operable sigue leyendo SOLO inventory_location_stocks.
        $this->assertStringContainsString(
            "InventoryLocationStock::where('inventory_location_id', \$location->id)",
            $src
        );
        $this->assertStringContainsString("->where('quantity', '>', 0)", $src);

        // La señal usa la clasificación por provenance del almacén de origen.
        $this->assertStringContainsString('private function legacyPendingForLocation(', $src);
        $this->assertStringContainsString('InventoryProvenanceAuditService::class)', $src);
        $this->assertStringContainsString('->auditWarehouse((int) $location->warehouse_id)', $src);
        $this->assertStringContainsString("\$kind = 'legacy_pending';", $src);
        $this->assertStringContainsString("\$kind = 'unknown_review';", $src);
        $this->assertStringContainsString("\$kind = 'other_location';", $src);
        $this->assertStringContainsString("'classification' => \$cls", $src);
    }

    public function test_transfer_form_explains_pending_by_provenance(): void
    {
        $vue = $this->read('resources/src/views/app/pages/transfers/next/form.vue');

        $this->assertStringContainsString('legacyPending', $vue);
        $this->assertStringContainsString('d.legacy_pending', $vue);
        $this->assertStringContainsString('pending.kind === "legacy_pending"', $vue);
        $this->assertStringContainsString('pending.kind === "unknown_review"', $vue);
        $this->assertStringContainsString('pending.kind === "other_location"', $vue);
        $this->assertStringContainsString('operación legacy posterior al último baseline', $vue);
        $this->assertStringContainsString('"Producto no encontrado."', $vue);
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
        // planIncremental usa la clasificación por PROVENANCE, no legacy - location.
        $this->assertStringContainsString('app(InventoryProvenanceAuditService::class)->auditWarehouse($warehouseId)', $src);
        $this->assertStringContainsString("if (! in_array(\$cls, ['LEGACY_ONLY_PENDING', 'UNKNOWN_REVIEW'], true)) continue;", $src);
        $this->assertStringNotContainsString('$legacyQty - $warehouseLocQty', $src);
        // El backfill de almacén completo sólo opera desde almacén sin stock y
        // remite al plan incremental cuando ya hay stock en cualquier ubicación.
        $this->assertStringContainsString('planIncremental / prodex:inventory-reconcile --plan', $src);
        $this->assertStringContainsString('! empty($this->warehouseLocationMap($warehouseId))', $src);
        // Sólo LEGACY_ONLY_PENDING puede ser ADD; los blockers lo pasan a review.
        $this->assertStringContainsString("if (\$cls === 'UNKNOWN_REVIEW') \$reasons[] = 'provenance_desconocida';", $src);
        $this->assertStringContainsString("\$reasons[] = 'lote_o_serie';", $src);
        $this->assertStringContainsString("\$reasons[] = 'reservado';", $src);
        $this->assertStringContainsString("\$reasons[] = 'transito_salida';", $src);
        $this->assertStringContainsString("'action' => (\$cls === 'LEGACY_ONLY_PENDING' && empty(\$reasons)) ? 'ADD' : 'MANUAL_REVIEW',", $src);
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

        // Contrato de destino APTO: predicado único reutilizado en todas partes.
        $this->assertStringContainsString('private function locationIsEligibleTarget(?InventoryLocation $location, int $warehouseId): bool', $src);
        $this->assertStringContainsString("&& \$location->type === InventoryLocation::TYPE_STORAGE", $src);
        $this->assertStringContainsString('&& ! $location->is_quarantine', $src);
        // ensureDefaultLocation NO usa existingDefaultLocation genérico: exige apto,
        // reutiliza / crea una code=MAIN storage, y rechaza una MAIN incompatible.
        $this->assertStringContainsString('$eligible = $this->eligibleLegacyTargetLocation($warehouse);', $src);
        $this->assertStringContainsString('if (! $this->locationIsEligibleTarget($main, $warehouse->id)) {', $src);
        $this->assertStringContainsString('No se recicla ni se modifica automáticamente: requiere revisión manual.', $src);
        // Aserción explícita justo antes de escribir.
        $this->assertStringContainsString('if (! $this->locationIsEligibleTarget($location, $warehouseId)) {', $src);

        // planIncremental nunca dice ADD sin destino.
        $this->assertStringContainsString("if (\$target === null) \$reasons[] = 'sin_ubicacion_destino';", $src);
        $this->assertStringContainsString('$target = $this->eligibleLegacyTargetLocation($warehouse);', $src);
    }

    public function test_detection_is_provenance_based_not_snapshot_delta(): void
    {
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        // Clasificación event-based; snapshot_drift es SÓLO diagnóstico.
        $this->assertStringContainsString("'RECONCILED'", $prov);
        $this->assertStringContainsString("'LEGACY_ONLY_PENDING'", $prov);
        $this->assertStringContainsString("'LOCATION_NATIVE_ONLY'", $prov);
        $this->assertStringContainsString("'MIRRORED'", $prov);
        $this->assertStringContainsString("'UNKNOWN_REVIEW'", $prov);
        $this->assertStringContainsString("legacy_product_warehouse_backfill", $prov); // baseline por movimiento
        $this->assertStringContainsString('last_reconciled_at', $prov);                // baseline por estado
        $this->assertStringContainsString("'snapshot_drift' => \$drift", $prov);

        $audit = $this->read('app/Services/LegacyInventoryReconciliationService.php');
        // is_reconciled ya NO se calcula por igualdad de snapshot.
        $this->assertStringContainsString('empty($unknownReview) && empty($legacyOnlyPending)', $audit);
        $this->assertStringNotContainsString("if (!\$this->same(\$legacyQty, \$locationQty)) {", $audit);

        // audit() de transición: mismatch = SOLO UNKNOWN_REVIEW + negativos.
        $compat = $this->read('app/Services/InventoryCompatibilityService.php');
        $this->assertStringContainsString("count(\$result['unknown_review_rows'] ?? []) + count(\$result['negative_legacy_rows'])", $compat);
        // Nunca mueve el baseline (last_reconciled_at) desde una auditoría.
        $this->assertStringNotContainsString("'last_reconciled_at' => \$result['is_reconciled'] ? now()", $compat);
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
        // …y rehúsa si el destino registrado dejó de ser apto en runtime.
        $this->assertStringContainsString('private function assertTargetStillEligible(int $warehouseId, int $locationId): void', $src);
        $this->assertStringContainsString('$this->assertTargetStillEligible($warehouseId, (int) $lockedState->inventory_location_id);', $src);
        $this->assertStringContainsString('$warehouseDefault === $locationId', $src);
        $this->assertStringContainsString('dejó de ser apta', $src);
    }
}
