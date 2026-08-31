<?php

namespace App\Services;

use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Motor location-aware para Ajustes y Daños de productos NO tracked (PR #81).
 *
 * Respalda LocationAwareAdjustmentService y LocationAwareDamageService: aplica y
 * revierte los efectos físicos de un documento sobre UNA inventory_location_id,
 * exclusivamente vía InventoryService increase / decrease (deltas de negocio) —
 * jamás escribe la tabla legacy ni dispara el mirror dual-write.
 *
 * SNAPSHOT DE EFECTOS (BLOCKER 2): CREATE construye y persiste el PLAN FÍSICO
 * EXACTO ya EXPANDIDO (componentes de combo incluidos) en
 * {adjustments,damages}.inventory_effect_snapshot. UPDATE/DESTROY revierten ESE
 * snapshot histórico — NUNCA vuelven a consultar CombinedProduct — de modo que
 * cambiar la composición de un combo no rompe la reversibilidad.
 *
 * BLOCKER 4/5: validateAndLock() se ejecuta DENTRO de la transacción del caller
 * con un orden de locks determinístico (Warehouse -> InventoryLocation ->
 * Products ASC -> ProductVariants ASC -> CombinedProduct). Los flags
 * is_batch_tracked / is_imei / deleted_at / type se leen de filas BLOQUEADAS.
 *
 * Los movimientos son LOCATION-NATIVE: reference_type Adjustment /
 * AdjustmentReversal / Damage / DamageReversal NO se añaden a
 * InventoryProvenanceAuditService::RECONCILIATION_REFS — cuentan en
 * post_baseline_native_net.
 */
class LocationAwareStockDocumentService
{
    public const REF_ADJUSTMENT = 'Adjustment';
    public const REF_ADJUSTMENT_REVERSAL = 'AdjustmentReversal';
    public const REF_DAMAGE = 'Damage';
    public const REF_DAMAGE_REVERSAL = 'DamageReversal';

    private const EPS = 0.0005;

    /** (D1) Sólo tipos con inventario físico entran al flujo location-aware. */
    public const INVENTORIABLE_TYPES = ['is_single', 'is_variant', 'is_combo'];

    // ===== Validación + locks (DENTRO de la transacción del caller) ==========

    /**
     * @param  array<int,array{product_id:mixed,product_variant_id?:mixed,quantity:mixed,type?:mixed}>  $lines
     * @param  int[]  $extraProductIds  ids extra a bloquear (p. ej. los del snapshot viejo en update)
     * @return array{location: InventoryLocation, lines: array<int,array{product_id:int,product_variant_id:?int,quantity:float,type:?string,product_type:string,components:array<int,array{product_id:int,quantity:float}>}>}
     *
     * @throws ValidationException
     */
    public function validateAndLock(int $warehouseId, ?int $locationId, array $lines, bool $requireType, array $extraProductIds = []): array
    {
        $this->assertInTransaction();

        // 2. Warehouse (bloqueado, no borrado).
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->whereNull('deleted_at')
            ->lockForUpdate()->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'El almacén no existe o está eliminado.']);
        }

        if (! $locationId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Debes seleccionar una ubicación de inventario para este almacén.',
            ]);
        }

        // 3. InventoryLocation (bloqueada). Debe seguir apta DENTRO de la tx.
        $location = InventoryLocation::whereKey($locationId)->lockForUpdate()->first();
        if (! $location || $location->deleted_at !== null || ! (bool) $location->is_active) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de inventario no existe, está inactiva o fue eliminada.',
            ]);
        }
        if ((int) $location->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de inventario no pertenece al almacén seleccionado.',
            ]);
        }

        if (empty($lines)) {
            throw ValidationException::withMessages(['details' => 'El documento no tiene líneas.']);
        }

        // Recolectar ids de línea + extra.
        $lineProductIds = array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines);
        $lineVariantIds = [];
        foreach ($lines as $l) {
            $v = $l['product_variant_id'] ?? null;
            if ($v !== null && $v !== '' && (int) $v > 0) $lineVariantIds[] = (int) $v;
        }

        // 4. Products ASC (bloqueados) — incl. extra ids del snapshot viejo.
        $allProductIds = array_values(array_unique(array_filter(array_merge(
            $lineProductIds, array_map('intval', $extraProductIds)
        ))));
        sort($allProductIds);
        $products = $allProductIds
            ? DB::table('products')->whereIn('id', $allProductIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        // Combos de las LÍNEAS: bloquear también sus componentes (Products) y las
        // filas CombinedProduct.
        $comboIds = [];
        foreach ($lines as $l) {
            $p = $products->get((int) ($l['product_id'] ?? 0));
            if ($p && $p->type === 'is_combo') $comboIds[] = (int) $p->id;
        }
        $comboComponentsByCombo = [];
        if ($comboIds) {
            sort($comboIds);
            $rows = DB::table('combined_products')->whereIn('product_id', $comboIds)
                ->orderBy('product_id')->orderBy('combined_product_id')->lockForUpdate()->get();
            $componentIds = $rows->pluck('combined_product_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
            sort($componentIds);
            $missingComponentIds = array_values(array_diff($componentIds, $products->keys()->all()));
            if ($missingComponentIds) {
                $more = DB::table('products')->whereIn('id', $missingComponentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($more as $id => $row) $products->put($id, $row);
            }
            foreach ($rows as $r) {
                $comboComponentsByCombo[(int) $r->product_id][] = [
                    'product_id' => (int) $r->combined_product_id,
                    'quantity' => round((float) $r->quantity, 3),
                ];
            }
        }

        // 5. ProductVariants ASC (bloqueadas).
        $variants = collect();
        if ($lineVariantIds && Schema::hasTable('product_variants')) {
            $ids = array_values(array_unique($lineVariantIds));
            sort($ids);
            $variants = DB::table('product_variants')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        }

        // Validar cada línea contra las filas BLOQUEADAS.
        $normalized = [];
        $trackedTargets = [];
        foreach ($lines as $i => $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $vid = isset($line['product_variant_id']) && $line['product_variant_id'] !== null && $line['product_variant_id'] !== '' && (int) $line['product_variant_id'] > 0
                ? (int) $line['product_variant_id'] : null;
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            $type = isset($line['type']) ? (string) $line['type'] : null;

            $product = $products->get($pid);
            if (! $product || $product->deleted_at !== null) {
                throw ValidationException::withMessages(["details.$i.product_id" => 'El producto de la línea '.($i + 1).' no existe o fue eliminado.']);
            }
            // (D1) sólo productos inventariables.
            if (! in_array((string) $product->type, self::INVENTORIABLE_TYPES, true)) {
                throw ValidationException::withMessages([
                    "details.$i.product_id" => 'El producto de la línea '.($i + 1).' no es inventariable (tipo: '.($product->type ?: 'desconocido').').',
                ]);
            }
            if ($qty <= self::EPS) {
                throw ValidationException::withMessages(["details.$i.quantity" => 'La cantidad debe ser mayor que cero.']);
            }
            if ($requireType && ! in_array($type, ['add', 'sub'], true)) {
                throw ValidationException::withMessages(["details.$i.type" => "El tipo de la línea ".($i + 1)." debe ser 'add' o 'sub'."]);
            }
            // (D2) invariante producto/variante.
            if ((string) $product->type === 'is_variant' && $vid === null) {
                throw ValidationException::withMessages([
                    "details.$i.product_variant_id" => 'El producto de la línea '.($i + 1).' es de variantes: falta product_variant_id.',
                ]);
            }
            if (in_array((string) $product->type, ['is_single', 'is_combo'], true) && $vid !== null) {
                throw ValidationException::withMessages([
                    "details.$i.product_variant_id" => 'El producto de la línea '.($i + 1).' no admite variante.',
                ]);
            }
            if ($vid !== null) {
                $variant = $variants->get($vid);
                if (! $variant || $variant->deleted_at !== null || (int) $variant->product_id !== $pid) {
                    throw ValidationException::withMessages([
                        "details.$i.product_variant_id" => 'La variante no existe, fue eliminada o no pertenece al producto.',
                    ]);
                }
            }

            $components = ($product->type === 'is_combo') ? ($comboComponentsByCombo[$pid] ?? []) : [];

            // (D3/D4/D7) Validar CADA componente ANTES de continuar. Nunca se
            // ignora en silencio un componente inexistente ni se genera un
            // snapshot con product_id inválido.
            if ((string) $product->type === 'is_combo' && empty($components)) {
                throw ValidationException::withMessages([
                    "details.$i.product_id" => 'El combo de la línea '.($i + 1).' no tiene componentes.',
                ]);
            }
            $trackedTargets[] = $product;
            foreach ($components as $c) {
                $cid = (int) $c['product_id'];
                $cp = $products->get($cid);
                if (! $cp || $cp->deleted_at !== null) {
                    throw ValidationException::withMessages([
                        "details.$i.product_id" => 'El combo de la línea '.($i + 1).' tiene un componente ('.$cid.') inexistente o eliminado.',
                    ]);
                }
                if (! in_array((string) $cp->type, self::INVENTORIABLE_TYPES, true)) {
                    throw ValidationException::withMessages([
                        "details.$i.product_id" => 'El combo de la línea '.($i + 1).' tiene un componente ('.$cid.') no inventariable.',
                    ]);
                }
                // CombinedProduct no representa variante específica: un componente
                // is_variant no puede mutarse quantity-only sin inventar variant_key.
                if ((string) $cp->type === 'is_variant') {
                    throw ValidationException::withMessages([
                        "details.$i.product_id" => 'El combo de la línea '.($i + 1).' tiene un componente de variantes ('.$cid.'), no soportado por el flujo location-aware.',
                    ]);
                }
                if ((float) $c['quantity'] <= self::EPS) {
                    throw ValidationException::withMessages([
                        "details.$i.product_id" => 'El combo de la línea '.($i + 1).' tiene un componente ('.$cid.') con cantidad no positiva.',
                    ]);
                }
                $trackedTargets[] = $cp;
            }

            $normalized[] = [
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'quantity' => $qty,
                'type' => $type,
                'product_type' => (string) $product->type,
                'components' => $components,
            ];
        }

        // (M) Rechazar TODO el request si CUALQUIER producto/componente es tracked.
        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        $trackedIds = [];
        foreach ($trackedTargets as $p) {
            if (($hasBatch && (int) ($p->is_batch_tracked ?? 0) === 1) || ($hasImei && (int) ($p->is_imei ?? 0) === 1)) {
                $trackedIds[(int) $p->id] = true;
            }
        }
        if ($trackedIds) {
            throw ValidationException::withMessages([
                'details' => 'Los ajustes/daños por ubicación de productos con lote o serie/IMEI se habilitarán mediante el flujo artifact-aware. '
                    .'Productos afectados: '.implode(', ', array_keys($trackedIds)).'.',
            ]);
        }

        return ['location' => $location, 'lines' => $normalized];
    }

    // ===== Construcción de snapshot (efectos EXPANDIDOS) ====================

    /**
     * (G) Ajuste: simple/variant add=>+qty / sub=>-qty; combo add => componentes
     * -(comp*qty), combo +qty; sub => inversa. Devuelve efectos ya expandidos.
     *
     * @param  array<int,array{product_id:int,product_variant_id:?int,quantity:float,type:string,product_type:string,components:array,detail_id?:int}>  $lines
     * @return array<int,array{product_id:int,variant_id:?int,delta:float,role:string,combo_parent_id:?int,action:string,detail_id:?int}>
     */
    public function buildAdjustmentSnapshot(array $lines): array
    {
        $effects = [];
        foreach ($lines as $line) {
            $pid = (int) $line['product_id'];
            $vid = $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null;
            $qty = round((float) $line['quantity'], 3);
            $isAdd = ($line['type'] ?? 'add') === 'add';
            $sign = $isAdd ? 1.0 : -1.0;
            $detailId = $line['detail_id'] ?? null;
            $action = $isAdd ? 'add' : 'sub';

            if (($line['product_type'] ?? 'is_single') === 'is_combo') {
                foreach ($line['components'] as $c) {
                    $effects[] = $this->effect((int) $c['product_id'], null, -$sign * (float) $c['quantity'] * $qty, 'combo_component', $pid, $action, $detailId);
                }
                $effects[] = $this->effect($pid, null, $sign * $qty, 'combo_parent', null, $action, $detailId);
            } else {
                $effects[] = $this->effect($pid, $vid, $sign * $qty, 'line', null, $action, $detailId);
            }
        }

        return $effects;
    }

    /**
     * (H) Daño: simple/variant => -qty; combo => componentes -(comp*qty) y combo
     * -qty. Devuelve efectos ya expandidos.
     */
    public function buildDamageSnapshot(array $lines): array
    {
        $effects = [];
        foreach ($lines as $line) {
            $pid = (int) $line['product_id'];
            $vid = $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null;
            $qty = round((float) $line['quantity'], 3);
            $detailId = $line['detail_id'] ?? null;

            if (($line['product_type'] ?? 'is_single') === 'is_combo') {
                foreach ($line['components'] as $c) {
                    $effects[] = $this->effect((int) $c['product_id'], null, -(float) $c['quantity'] * $qty, 'combo_component', $pid, 'damage', $detailId);
                }
                $effects[] = $this->effect($pid, null, -$qty, 'combo_parent', null, 'damage', $detailId);
            } else {
                $effects[] = $this->effect($pid, $vid, -$qty, 'line', null, 'damage', $detailId);
            }
        }

        return $effects;
    }

    private function effect(int $productId, ?int $variantId, float $delta, string $role, ?int $comboParentId, string $action, $detailId): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'delta' => round($delta, 3),
            'role' => $role,
            'combo_parent_id' => $comboParentId,
            'action' => $action,
            'detail_id' => $detailId !== null ? (int) $detailId : null,
        ];
    }

    /**
     * Normaliza un snapshot persistido (JSON string o array). FAIL CLOSED si un
     * documento location-aware no lo tiene o está corrupto.
     */
    public function normalizeSnapshot($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'El documento location-aware no tiene un snapshot de efectos válido; no se revierte reconstruyendo la composición actual.',
            ]);
        }
        $out = [];
        foreach ($raw as $e) {
            if (! isset($e['product_id']) || ! array_key_exists('delta', $e)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot de efectos corrupto.']);
            }
            $out[] = [
                'product_id' => (int) $e['product_id'],
                'variant_id' => isset($e['variant_id']) && $e['variant_id'] !== null ? (int) $e['variant_id'] : null,
                'delta' => round((float) $e['delta'], 3),
                'role' => (string) ($e['role'] ?? 'line'),
                'combo_parent_id' => isset($e['combo_parent_id']) && $e['combo_parent_id'] !== null ? (int) $e['combo_parent_id'] : null,
                'action' => (string) ($e['action'] ?? 'add'),
                'detail_id' => isset($e['detail_id']) && $e['detail_id'] !== null ? (int) $e['detail_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * (D5/D6/D7) Guard histórico: un snapshot creado sobre productos NO tracked
     * no puede revertirse quantity-only si algún producto AHORA es batch-tracked
     * o IMEI. Se ejecuta DENTRO de la transacción y ANTES de reverseSnapshot().
     *
     * - lock Products del snapshot en orden ASC;
     * - (D8) NO exige whereNull('deleted_at'): un producto normal soft-deleted
     *   tras el documento debe poder revertirse;
     * - si un product_id del snapshot está HARD-MISSING (sin fila) => FAIL CLOSED;
     * - si cualquiera es tracked ahora => FAIL CLOSED. 0 stock / 0 movimientos.
     *
     * @throws ValidationException
     */
    public function assertSnapshotArtifactSafeAndLock(array $snapshot): void
    {
        $this->assertInTransaction();

        $ids = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $snapshot)));
        sort($ids);
        if (! $ids) return;

        $rows = DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');

        $missing = [];
        $nowTracked = [];
        foreach ($ids as $id) {
            $p = $rows->get($id);
            if (! $p) {
                $missing[] = $id;

                continue;
            }
            if (($hasBatch && (int) ($p->is_batch_tracked ?? 0) === 1) || ($hasImei && (int) ($p->is_imei ?? 0) === 1)) {
                $nowTracked[] = $id;
            }
        }

        if ($missing) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'No se puede revertir el documento: productos del snapshot ya no existen ('.implode(', ', $missing).'). '
                    .'FAIL CLOSED — requiere revisión manual.',
            ]);
        }
        if ($nowTracked) {
            throw ValidationException::withMessages([
                'inventory_effect_snapshot' => 'No se puede revertir el documento con una operación quantity-only: los productos '
                    .implode(', ', $nowTracked).' ahora llevan control de lote o serie/IMEI. Requiere el flujo artifact-aware.',
            ]);
        }
    }

    // ===== Aplicación física ================================================

    public function applySnapshot(array $effects, string $referenceType, int $referenceId, int $warehouseId, int $locationId, string $source): void
    {
        $this->applyEffects($effects, false, $referenceType, $referenceId, $warehouseId, $locationId, $source);
    }

    /** Revierte EXACTAMENTE el snapshot dado (negando cada delta). */
    public function reverseSnapshot(array $effects, string $referenceType, int $referenceId, int $warehouseId, int $locationId, string $source): void
    {
        $this->applyEffects($effects, true, $referenceType, $referenceId, $warehouseId, $locationId, $source);
    }

    private function applyEffects(array $effects, bool $reverse, string $referenceType, int $referenceId, int $warehouseId, int $locationId, string $source): void
    {
        $this->assertInTransaction();

        $effects = array_map(function ($e) use ($reverse) {
            $e['delta'] = round($reverse ? -$e['delta'] : $e['delta'], 3);
            $e['variant_key'] = (int) ($e['variant_id'] ?? 0);

            return $e;
        }, $effects);
        $effects = array_values(array_filter($effects, fn ($e) => abs($e['delta']) > self::EPS));

        // Orden determinístico por (product_id, variant_key) — menos deadlocks.
        usort($effects, fn ($a, $b) => [$a['product_id'], $a['variant_key']] <=> [$b['product_id'], $b['variant_key']]);

        $documentType = in_array($referenceType, [self::REF_DAMAGE, self::REF_DAMAGE_REVERSAL], true) ? 'damage' : 'adjustment';
        $inventory = app(InventoryService::class);

        foreach ($effects as $e) {
            $qty = abs($e['delta']);
            $direction = $e['delta'] > 0 ? 'increase' : 'decrease';
            $context = [
                'user_id' => function_exists('auth') ? auth()->id() : null,
                'reference_type' => $referenceType,
                'reference_id' => (string) $referenceId,
                'notes' => $referenceType.' '.$source,
                'metadata' => [
                    'warehouse_id' => $warehouseId,
                    'inventory_location_id' => $locationId,
                    'document_type' => $documentType,
                    'document_id' => $referenceId,
                    'detail_id' => $e['detail_id'] ?? null,
                    'product_id' => $e['product_id'],
                    'variant_id' => $e['variant_id'] ?? null,
                    'direction' => $direction,
                    'action' => $e['action'] ?? null,
                    'role' => $e['role'] ?? 'line',
                    'combo_parent_id' => $e['combo_parent_id'] ?? null,
                    'source' => $source,
                ],
            ];

            if ($e['delta'] > 0) {
                $inventory->increase($locationId, $e['product_id'], $qty, $e['variant_id'] ?? null, $context);
            } else {
                $inventory->decrease($locationId, $e['product_id'], $qty, $e['variant_id'] ?? null, $context);
            }
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw ValidationException::withMessages([
                'inventory' => 'LocationAwareStockDocumentService debe ejecutarse dentro de la transacción del documento.',
            ]);
        }
    }
}
