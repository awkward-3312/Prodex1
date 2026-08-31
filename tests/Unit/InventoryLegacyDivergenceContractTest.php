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
        // --plan / --apply / --apply-incremental son mutuamente excluyentes.
        $this->assertStringContainsString("count(array_filter([\$apply, \$plan, \$applyIncremental])) > 1", $src);
        $this->assertStringContainsString('Usa sólo uno de --plan', $src);
    }

    public function test_reconcile_command_has_incremental_apply_mode_distinct_from_backfill(): void
    {
        $cmd = $this->read('app/Console/Commands/ProdexInventoryReconcile.php');
        // Operación explícita separada del --apply (backfill de almacén vacío).
        $this->assertStringContainsString('--apply-incremental', $cmd);
        $this->assertStringContainsString('--apply-incremental requiere exactamente un --tenants=<tenant>.', $cmd);
        $this->assertStringContainsString('--apply-incremental requiere --warehouse=<id>.', $cmd);
        // K9: v1 exige --product para ESCRIBIR; --plan (lectura) no.
        $this->assertStringContainsString('--apply-incremental requiere --product=<id>', $cmd);
        $this->assertStringContainsString('--product sólo aplica junto con --apply-incremental.', $cmd);
        $this->assertStringContainsString('$service->applyIncremental($warehouseId, $pid, $expect)', $cmd);
        // Summary distingue ADD candidates / MANUAL_REVIEW / failures (ya no
        // "0 pending differences" cuando hay ADD).
        $this->assertStringContainsString('%d ADD candidates, %d MANUAL_REVIEW, %d failures.', $cmd);
        $this->assertStringContainsString('%d applied, %d MANUAL_REVIEW, %d failures.', $cmd);

        $svc = $this->read('app/Services/LegacyInventoryReconciliationService.php');
        $this->assertStringContainsString('public function applyIncremental(int $warehouseId, ?int $productId = null, ?array $expect = null, ?int $userId = null): array', $svc);
        // Revalida provenance DENTRO de la transacción (nunca un plan viejo).
        $this->assertStringContainsString('$planNow = $this->planIncremental($warehouseId);', $svc);
        $this->assertStringContainsString('El plan quedó obsoleto para el producto', $svc);
        // Sólo LEGACY_ONLY_PENDING + ADD; delta > 0; nunca auto-decremento.
        $this->assertStringContainsString("\$r['action'] !== 'ADD' || \$r['classification'] !== 'LEGACY_ONLY_PENDING'", $svc);
        $this->assertStringContainsString('nunca se aplica auto-decremento', $svc);
        // Suma sólo el delta vía InventoryService::increase(), nunca adjustTo().
        $this->assertStringContainsString('$inventory->increase(', $svc);
        $this->assertStringNotContainsString('$inventory->adjustTo(', $svc);
        // Idempotencia por fingerprint del estado revisado, no warehouse:product:variant:delta.
        $this->assertStringContainsString("'legacy-incremental-reconcile:'.\$warehouseId.':'.\$pid.':'.\$variantKey.':'.\$fingerprint", $svc);
        $this->assertStringContainsString("'reconciliation_fingerprint' => \$fingerprint", $svc);
        // Postcondición: re-audit sólo de las claves aplicadas; rollback si falla.
        $this->assertStringContainsString('La postcondición falló para la clave', $svc);
        $this->assertStringContainsString("in_array(\$row['classification'], ['LEGACY_ONLY_PENDING', 'UNKNOWN_REVIEW'], true)", $svc);
    }

    public function test_apply_incremental_locks_calc_sources_before_replan(): void
    {
        $svc = $this->read('app/Services/LegacyInventoryReconciliationService.php');

        // (A) K9: la ESCRITURA incremental exige --product en v1. El batch sin
        // --product no escribe; --plan (lectura) no lo exige.
        $this->assertStringContainsString('if ($productId === null) {', $svc);
        $this->assertStringContainsString('apply-incremental (escritura) requiere --product=<id> en v1', $svc);

        // (B) K5: antes de REPLANIFICAR, applyIncremental bloquea EXPLÍCITAMENTE
        // (no vía gap-locks) todas las filas que sustentan/alteran el cálculo:
        //   B.1 product_warehouse del producto;
        //   B.2 la fila products (is_batch_tracked / is_imei / deleted_at);
        //   B.3 la ubicación TARGET;
        //   B.5 firstOrCreate(0) + lockForUpdate de inventory_location_stocks en
        //       CADA ubicación activa × variant_key.
        $this->assertStringContainsString("->where('product_id', \$productId)->lockForUpdate()->get();", $svc);
        $this->assertStringContainsString("DB::table('products')->where('id', \$productId)->lockForUpdate()->get();", $svc);
        $this->assertStringContainsString('InventoryLocation::whereKey($target->id)->lockForUpdate()->get();', $svc);
        $this->assertStringContainsString('InventoryLocationStock::firstOrCreate(', $svc);
        $this->assertStringContainsString("InventoryLocationStock::whereIn('inventory_location_id', \$activeLocationIds)", $svc);

        // Todos los locks se toman ANTES de $this->planIncremental() en el método.
        $replanPos = strpos($svc, '$planNow = $this->planIncremental($warehouseId);');
        $this->assertNotFalse($replanPos);
        foreach ([
            "DB::table('products')->where('id', \$productId)->lockForUpdate()",
            'InventoryLocation::whereKey($target->id)->lockForUpdate()',
            "->where('product_id', \$productId)->lockForUpdate()->get();",
            'InventoryLocationStock::firstOrCreate(',
            "InventoryLocationStock::whereIn('inventory_location_id', \$activeLocationIds)",
        ] as $needle) {
            $pos = strpos($svc, $needle);
            $this->assertNotFalse($pos, "no se encontró: $needle");
            $this->assertLessThan($replanPos, $pos, "$needle debe preceder al replan");
        }

        // (C) revalidación del conjunto de ubicaciones activas antes del write.
        $this->assertStringContainsString('El conjunto de ubicaciones activas del almacén cambió durante el apply', $svc);
        $this->assertStringContainsString('$activeLocationIdsNow !== $activeLocationIds', $svc);
        $revalPos = strpos($svc, '$activeLocationIdsNow !== $activeLocationIds');
        $this->assertNotFalse($revalPos);
        $this->assertGreaterThan($replanPos, $revalPos, 'la revalidación de ubicaciones va tras el replan');

        // El comentario documenta por qué esos locks serializan a los writers y
        // la limitación (SQLite en el suite Unit no bloquea de verdad).
        $this->assertStringContainsString('UPDATE product_warehouse.qte', $svc);
        $this->assertStringContainsString('DEBITA el stock', $svc);

        // (D) Comparación del CONJUNTO completo de claves ADD esperado vs recalculado.
        $this->assertStringContainsString('conjunto de claves ADD ya no coincide', $svc);
        $this->assertStringContainsString('array_keys($expectedAdd) !== array_keys($planNowAdd)', $svc);
    }

    public function test_incremental_reconciliation_ref_is_a_baseline_category_not_native_net(): void
    {
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        $this->assertStringContainsString("public const INCREMENTAL_RECONCILIATION_REF = 'legacy_product_warehouse_incremental_reconciliation';", $prov);
        $this->assertStringContainsString('public const RECONCILIATION_REFS = [', $prov);
        // Cuenta como baseline_quantity de su clave...
        $this->assertStringContainsString('->whereIn(\'reference_type\', self::RECONCILIATION_REFS)', $prov);
        // ...y queda FUERA del net posterior (por tanto fuera de native_net).
        $this->assertStringContainsString('->whereNotIn(\'reference_type\', self::RECONCILIATION_REFS)', $prov);
        // NO mueve el baseline temporal global del almacén (sólo BACKFILL_REF).
        $this->assertStringContainsString("->where('reference_type', self::BACKFILL_REF)", $prov);
        $this->assertStringContainsString('NO debe adelantar este', $prov);
    }

    /**
     * L13 — Stock inicial CENTRALIZADO: los paths conocidos ya NO tienen lógica
     * independiente que incremente qte sin pasar por OpeningStockInventoryService.
     */
    public function test_opening_stock_is_centralized_in_a_single_service(): void
    {
        // El servicio dedicado existe y hace la escritura atómica legacy+location.
        $svc = $this->read('app/Services/OpeningStockInventoryService.php');
        $this->assertStringContainsString('class OpeningStockInventoryService', $svc);
        $this->assertStringContainsString("public const REFERENCE_TYPE = 'legacy_product_warehouse_opening_stock_sync';", $svc);
        $this->assertStringContainsString('public function applyOpeningStock(int $warehouseId, int $productId, ?int $variantId, float $qty, array $context = []): void', $svc);
        // (C) atómico: increase(delta), nunca adjustTo().
        $this->assertStringContainsString('app(InventoryService::class)->increase(', $svc);
        $this->assertStringNotContainsString('->adjustTo(', $svc);
        // (D) evitar doble mirror: la escritura legacy es saveQuietly().
        $this->assertStringContainsString('$pw->saveQuietly();', $svc);
        // (H) sin default apta => abort, nunca legacy-only.
        $this->assertStringContainsString('El almacén necesita una ubicación principal activa de tipo almacenamiento', $svc);
        // (4) contrato de ubicación destino.
        $this->assertStringContainsString('$location->type !== InventoryLocation::TYPE_STORAGE', $svc);
        $this->assertStringContainsString('$location->is_quarantine', $svc);
        $this->assertStringContainsString('(int) $location->warehouse_id !== $warehouseId', $svc);
        // (G) batch / IMEI con qty>0 => rechazo.
        $this->assertStringContainsString('El producto lleva control de lote o serie/IMEI', $svc);

        // ---- Iteración 2 ----
        // (BLOCKER 3) contrato EJECUTABLE de transacción.
        $this->assertStringContainsString('if (DB::transactionLevel() <= 0) {', $svc);
        $this->assertStringContainsString('debe ejecutarse dentro de una transacción de negocio', $svc);
        // (BLOCKER 1) sólo filas ACTIVAS; nunca resucitar soft-deleted; >1 activa => abort.
        $this->assertStringContainsString("->whereNull('deleted_at')\n            ->lockForUpdate()\n            ->get();", $svc);
        $this->assertStringContainsString('if ($activeRows->count() > 1) {', $svc);
        $this->assertStringContainsString('filas product_warehouse ACTIVAS para la misma clave', $svc);
        $this->assertStringContainsString('$pw = new product_warehouse;', $svc); // fila NUEVA activa
        // (BLOCKER 2) ORDEN FIJO de locks: Warehouse -> Product -> Variant -> pw -> location.
        $whLockPos = strpos($svc, "DB::table('warehouses')");
        $prodLockPos = strpos($svc, "DB::table('products')");
        $varLockPos = strpos($svc, "DB::table('product_variants')");
        $defaultReadPos = strpos($svc, '$warehouse->default_inventory_location_id');
        $trackedGuardPos = strpos($svc, "(int) (\$product->is_batch_tracked ?? 0) === 1");
        $this->assertNotFalse($whLockPos);
        $this->assertNotFalse($prodLockPos);
        $this->assertNotFalse($varLockPos);
        // M4: la fila Warehouse se bloquea ANTES de leer default_inventory_location_id.
        $this->assertLessThan($defaultReadPos, $whLockPos, 'warehouse lock antes de resolver la default location');
        // M3: la fila Product se bloquea ANTES del guard batch/IMEI.
        $this->assertLessThan($trackedGuardPos, $prodLockPos, 'product lock antes del guard batch/IMEI');
        // orden Warehouse -> Product -> Variant.
        $this->assertLessThan($prodLockPos, $whLockPos);
        $this->assertLessThan($varLockPos, $prodLockPos);
        // los flags batch/IMEI se leen de la fila Product BLOQUEADA.
        $this->assertStringContainsString('(int) ($product->is_batch_tracked ?? 0) === 1', $svc);
        $this->assertStringContainsString('(int) ($product->is_imei ?? 0) === 1', $svc);
        // la default location se resuelve desde la fila Warehouse BLOQUEADA.
        $this->assertStringContainsString('$warehouse->default_inventory_location_id', $svc);

        // (E) provenance: el nuevo reference_type es RECONCILIATION legacy→location.
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        $this->assertStringContainsString("public const OPENING_STOCK_RECONCILIATION_REF = 'legacy_product_warehouse_opening_stock_sync';", $prov);
        $this->assertStringContainsString('self::OPENING_STOCK_RECONCILIATION_REF,', $prov);

        // ProductsController: los 3 paths de stock inicial delegan en el servicio
        // y NO incrementan qte por su cuenta.
        $pc = $this->read('app/Http/Controllers/ProductsController.php');
        $this->assertSame(3, substr_count($pc, '->applyOpeningStock('), 'store + import_single + import_variants');
        // store: las filas product_warehouse nacen SIEMPRE a 0.
        $this->assertStringContainsString('// bulk insert (todas a qte = 0)', $pc);
        $this->assertStringNotContainsString("\$pw->qte = (float) \$pw->qte + (float) \$c['qty'];", $pc);
        // El Adjustment/COGS virtual se conserva (no mueve stock por sí mismo).
        $this->assertStringContainsString("'notes' => 'Opening stock (auto)',", $pc);
        $this->assertStringContainsString("'notes' => 'Opening stock import (auto)',", $pc);
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

    public function test_dual_write_requires_snapshot_equality_and_mirror_rejects_drift(): void
    {
        $audit = $this->read('app/Services/LegacyInventoryReconciliationService.php');
        // Dos señales SEPARADAS.
        $this->assertStringContainsString("'provenance_reconciled' => \$isReconciled", $audit);
        $this->assertStringContainsString("'snapshot_equal' => \$snapshotEqual", $audit);
        $this->assertStringContainsString("'dual_write_compatible' =>", $audit);
        // snapshot_equal = paridad legacy_now vs location actual por clave.
        $this->assertStringContainsString("abs((float) \$r['legacy_now'] - (float) \$r['current_location']) > 0.0005", $audit);

        $compat = $this->read('app/Services/InventoryCompatibilityService.php');
        // enableMode: dual_write exige provenance_reconciled + destino + single-target + snapshot_equal.
        $this->assertStringContainsString("! (\$audit['provenance_reconciled'] ?? \$audit['is_reconciled'] ?? false)", $compat);
        $this->assertStringContainsString("! (\$audit['snapshot_equal'] ?? false)", $compat);
        $this->assertStringContainsString('dual_write requiere paridad actual legacy/location; existen movimientos location-native posteriores al baseline.', $compat);
        // mirrorLegacySnapshot: guard usa el net NATIVO (independiente del mirror),
        // NO todos los movimientos — si no, dual_write se autobloquearía.
        $this->assertStringContainsString("\$provKey['post_baseline_native_net']", $compat);
        $this->assertStringContainsString('if (abs($nativeNet) > 0.0005) {', $compat);
        $this->assertStringContainsString('El mirror single-target recrearía stock ya movido', $compat);
        // provenance separa los netos: location (todos) / mirror / native.
        $prov = $this->read('app/Services/InventoryProvenanceAuditService.php');
        $this->assertStringContainsString("public const DUAL_WRITE_MIRROR_REFS = [", $prov);
        $this->assertStringContainsString("'post_baseline_mirror_net' => \$nMirror", $prov);
        $this->assertStringContainsString("'post_baseline_native_net' => \$nNative", $prov);
        $this->assertStringContainsString('$nNative = round($n - $nMirror, 3);', $prov);
        // MIRRORED se prueba por IDENTIDAD de evento, no por coincidencia de cantidad.
        $this->assertStringContainsString('public function matchLegacyEventToLocationMovement(', $prov);
        $this->assertStringContainsString('if ($this->matchLegacyEventToLocationMovement($m)) {', $prov);
        $this->assertStringNotContainsString('LEGACY_MIRROR_REFS = [', $prov);
        // Aumento legacy + actividad location-native no-mirror => UNKNOWN_REVIEW,
        // nunca MIRRORED por agregado.
        $this->assertStringContainsString('} elseif (abs($nNative) > self::EPS) {', $prov);
        // compareKey: definición ÚNICA de mismatch = provenance (RECONCILED|MIRRORED).
        $this->assertStringContainsString("in_array(\$classification, ['RECONCILED', 'MIRRORED'], true)", $compat);
        $this->assertStringContainsString('public function snapshotCompareKey(', $compat);
        // enableMode NO reescribe el baseline al activar un modo.
        $this->assertStringContainsString("last_reconciled_at (baseline provenance) NO se toca al activar un modo", $compat);
        $this->assertStringNotContainsString("'last_reconciled_at' => now(),", $compat);
        // El movimiento del mirror siempre se marca legacy_shadow_sync.
        $this->assertStringContainsString("'reference_type' => 'legacy_shadow_sync',", $compat);
    }

    public function test_dual_write_rejects_batch_tracked_or_imei_inventory(): void
    {
        // El mirror single-target usa InventoryService::adjustTo(), que ajusta
        // inventory_location_stocks.quantity pero NO mantiene
        // product_batch_location_stocks ni product_serials. Hasta que exista un
        // mirror artifact-aware, dual_write se rechaza para esos almacenes.
        $audit = $this->read('app/Services/LegacyInventoryReconciliationService.php');
        $this->assertStringContainsString("'has_tracked_inventory' => \$hasTrackedInventory", $audit);
        $this->assertStringContainsString("'dual_write_artifact_safe' => ! \$hasTrackedInventory", $audit);
        $this->assertStringContainsString("'tracked_inventory_product_ids' => array_keys(\$trackedIdsWithInventory)", $audit);
        // dual_write_compatible incorpora la condición.
        $this->assertStringContainsString('&& $snapshotEqual && ! $hasTrackedInventory', $audit);
        // "Inventario relevante" = legacy o location > EPS (no bloquea por un
        // producto tracked sin existencia real).
        $this->assertStringContainsString("(float) \$r['legacy_now'] > 0.0005 || (float) \$r['current_location'] > 0.0005", $audit);
        $this->assertStringContainsString('private function trackedProductIds(array $productIds): array', $audit);
        $this->assertStringContainsString("if (\$hasBatch) \$query->orWhere('is_batch_tracked', 1);", $audit);
        $this->assertStringContainsString("if (\$hasImei) \$query->orWhere('is_imei', 1);", $audit);

        $compat = $this->read('app/Services/InventoryCompatibilityService.php');
        // enableMode: MODE_DUAL_WRITE rechaza has_tracked_inventory.
        $this->assertStringContainsString("if ((\$audit['has_tracked_inventory'] ?? false)) {", $compat);
        $this->assertStringContainsString('dual_write no está soportado mientras el almacén tenga inventario de productos batch-tracked o IMEI', $compat);
        // Guard runtime en mirrorLegacySnapshot: si ESTE producto es tracked,
        // throw + markMismatch, 0 adjustTo.
        $this->assertStringContainsString('if ($this->productIsArtifactTracked($productId)) {', $compat);
        $this->assertStringContainsString('Dual-write detenido: el producto es batch-tracked o IMEI;', $compat);
        $this->assertStringContainsString('private function productIsArtifactTracked(int $productId): bool', $compat);
        // El guard corre ANTES de calcular el target / adjustTo.
        $this->assertMatchesRegularExpression(
            '/productIsArtifactTracked\(\$productId\)\).*\$target = \$this->legacyQuantity\(/s',
            $compat
        );
    }
}
