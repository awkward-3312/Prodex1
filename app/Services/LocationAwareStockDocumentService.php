<?php

namespace App\Services;

use App\Models\CombinedProduct;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
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
 * Los movimientos generados son LOCATION-NATIVE: sus reference_type
 * (Adjustment / AdjustmentReversal / Damage / DamageReversal) NO se añaden a
 * InventoryProvenanceAuditService::RECONCILIATION_REFS — cuentan en
 * post_baseline_native_net.
 *
 * DEBE ejecutarse dentro de la transacción del controller (transactionLevel>0).
 */
class LocationAwareStockDocumentService
{
    public const REF_ADJUSTMENT = 'Adjustment';
    public const REF_ADJUSTMENT_REVERSAL = 'AdjustmentReversal';
    public const REF_DAMAGE = 'Damage';
    public const REF_DAMAGE_REVERSAL = 'DamageReversal';

    private const EPS = 0.0005;

    // ---- Validación de request location-aware ---------------------------------

    /**
     * Valida un request location-aware ANTES de crear header/details/movements.
     *
     * @param  array<int,array{product_id:int,product_variant_id:?int,quantity:mixed,type?:string}>  $lines
     * @return array{location: InventoryLocation, lines: array<int,array{product_id:int,product_variant_id:?int,quantity:float,type:?string,product_type:string}>}
     *
     * @throws ValidationException
     */
    public function validateRequest(int $warehouseId, ?int $locationId, array $lines, bool $requireType): array
    {
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->whereNull('deleted_at')->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'El almacén no existe o está eliminado.']);
        }

        if (! $locationId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Debes seleccionar una ubicación de inventario para este almacén.',
            ]);
        }

        $location = InventoryLocation::whereKey($locationId)->first();
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

        // Recolectar todos los product_id referenciados, incl. componentes de combo.
        $normalized = [];
        $allProductIds = [];
        foreach ($lines as $i => $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $vid = isset($line['product_variant_id']) && $line['product_variant_id'] !== null && $line['product_variant_id'] !== ''
                ? (int) $line['product_variant_id'] : null;
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            $type = isset($line['type']) ? (string) $line['type'] : null;

            $product = Product::whereNull('deleted_at')->whereKey($pid)->first();
            if (! $product) {
                throw ValidationException::withMessages(["details.$i.product_id" => "El producto de la línea ".($i + 1)." no existe."]);
            }
            if ($qty <= self::EPS) {
                throw ValidationException::withMessages(["details.$i.quantity" => 'La cantidad debe ser mayor que cero.']);
            }
            if ($requireType && ! in_array($type, ['add', 'sub'], true)) {
                throw ValidationException::withMessages(["details.$i.type" => "El tipo de la línea ".($i + 1)." debe ser 'add' o 'sub'."]);
            }
            if ($vid !== null) {
                $variant = ProductVariant::whereNull('deleted_at')->whereKey($vid)->first();
                if (! $variant || (int) $variant->product_id !== $pid) {
                    throw ValidationException::withMessages([
                        "details.$i.product_variant_id" => 'La variante no existe o no pertenece al producto.',
                    ]);
                }
            }

            $allProductIds[] = $pid;
            if ($product->type === 'is_combo') {
                foreach ($this->comboComponents($pid) as $component) {
                    $allProductIds[] = (int) $component->combined_product_id;
                }
            }

            $normalized[] = [
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'quantity' => $qty,
                'type' => $type,
                'product_type' => (string) $product->type,
            ];
        }

        // (M) Rechazar TODO el request si CUALQUIER producto (o componente) es
        // batch-tracked o IMEI. Sin fallback silencioso a product_warehouse.
        $tracked = $this->trackedProductIds(array_values(array_unique($allProductIds)));
        if ($tracked) {
            throw ValidationException::withMessages([
                'details' => 'Los ajustes/daños por ubicación de productos con lote o serie/IMEI se habilitarán mediante el flujo artifact-aware. '
                    .'Productos afectados: '.implode(', ', $tracked).'.',
            ]);
        }

        return ['location' => $location, 'lines' => $normalized];
    }

    // ---- Ajustes ------------------------------------------------------------

    public function applyAdjustment(int $adjustmentId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->assertInTransaction();
        $this->applyEffects(
            $this->adjustmentEffects($lines, reverse: false),
            self::REF_ADJUSTMENT, $adjustmentId, $warehouseId, $locationId, $source
        );
    }

    public function reverseAdjustment(int $adjustmentId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->assertInTransaction();
        $this->applyEffects(
            $this->adjustmentEffects($lines, reverse: true),
            self::REF_ADJUSTMENT_REVERSAL, $adjustmentId, $warehouseId, $locationId, $source
        );
    }

    // ---- Daños ------------------------------------------------------------

    public function applyDamage(int $damageId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->assertInTransaction();
        $this->applyEffects(
            $this->damageEffects($lines, reverse: false),
            self::REF_DAMAGE, $damageId, $warehouseId, $locationId, $source
        );
    }

    public function reverseDamage(int $damageId, int $warehouseId, int $locationId, array $lines, string $source): void
    {
        $this->assertInTransaction();
        $this->applyEffects(
            $this->damageEffects($lines, reverse: true),
            self::REF_DAMAGE_REVERSAL, $damageId, $warehouseId, $locationId, $source
        );
    }

    // ---- Semántica de efectos --------------------------------------------

    /**
     * (G) Semántica EXACTA de ajuste:
     *   simple/variant add  => +qty         | sub  => -qty
     *   combo       add  => componentes -(comp.qty*qty), combo +qty
     *               sub  => componentes +(comp.qty*qty), combo -qty
     * reverse = negar cada delta.
     */
    private function adjustmentEffects(array $lines, bool $reverse): array
    {
        $effects = [];
        foreach ($lines as $line) {
            $pid = (int) $line['product_id'];
            $vid = $line['product_variant_id'];
            $qty = (float) $line['quantity'];
            $isAdd = ($line['type'] ?? 'add') === 'add';
            $sign = $isAdd ? 1.0 : -1.0;
            $ptype = $line['product_type'] ?? 'is_single';
            $detailId = $line['detail_id'] ?? null;

            if ($ptype === 'is_combo') {
                foreach ($this->comboComponents($pid) as $component) {
                    $effects[] = $this->effect(
                        (int) $component->combined_product_id, null,
                        -$sign * (float) $component->quantity * $qty,
                        $detailId, $pid, 'combo_component', $isAdd ? 'add' : 'sub'
                    );
                }
                $effects[] = $this->effect($pid, null, $sign * $qty, $detailId, null, 'combo_parent', $isAdd ? 'add' : 'sub');
            } else {
                $effects[] = $this->effect($pid, $vid, $sign * $qty, $detailId, null, 'line', $isAdd ? 'add' : 'sub');
            }
        }

        return $reverse ? $this->negate($effects) : $effects;
    }

    /**
     * (H) Semántica EXACTA de daño (siempre salida física):
     *   simple/variant => -qty
     *   combo          => componentes -(comp.qty*qty), combo -qty
     * reverse = negar cada delta (increase de lo que el daño disminuyó).
     */
    private function damageEffects(array $lines, bool $reverse): array
    {
        $effects = [];
        foreach ($lines as $line) {
            $pid = (int) $line['product_id'];
            $vid = $line['product_variant_id'];
            $qty = (float) $line['quantity'];
            $ptype = $line['product_type'] ?? 'is_single';
            $detailId = $line['detail_id'] ?? null;

            if ($ptype === 'is_combo') {
                foreach ($this->comboComponents($pid) as $component) {
                    $effects[] = $this->effect(
                        (int) $component->combined_product_id, null,
                        -(float) $component->quantity * $qty,
                        $detailId, $pid, 'combo_component', 'damage'
                    );
                }
                $effects[] = $this->effect($pid, null, -$qty, $detailId, null, 'combo_parent', 'damage');
            } else {
                $effects[] = $this->effect($pid, $vid, -$qty, $detailId, null, 'line', 'damage');
            }
        }

        return $reverse ? $this->negate($effects) : $effects;
    }

    private function effect(int $productId, ?int $variantId, float $delta, $detailId, $comboParentId, string $role, string $action): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0),
            'delta' => round($delta, 3),
            'detail_id' => $detailId,
            'combo_parent_id' => $comboParentId,
            'role' => $role,
            'action' => $action,
        ];
    }

    private function negate(array $effects): array
    {
        return array_map(function ($e) {
            $e['delta'] = round(-$e['delta'], 3);

            return $e;
        }, $effects);
    }

    // ---- Aplicación física ----------------------------------------------

    /**
     * Ordena determinísticamente por (product_id, variant_key) para reducir
     * deadlocks y aplica cada efecto vía InventoryService. Un fallo en cualquier
     * decremento => ValidationException => rollback total (transacción del caller).
     */
    private function applyEffects(array $effects, string $referenceType, int $referenceId, int $warehouseId, int $locationId, string $source): void
    {
        $effects = array_values(array_filter($effects, fn ($e) => abs($e['delta']) > self::EPS));
        usort($effects, function ($a, $b) {
            return [$a['product_id'], $a['variant_key']] <=> [$b['product_id'], $b['variant_key']];
        });

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
                    'document_type' => in_array($referenceType, [self::REF_DAMAGE, self::REF_DAMAGE_REVERSAL], true) ? 'damage' : 'adjustment',
                    'document_id' => $referenceId,
                    'detail_id' => $e['detail_id'],
                    'product_id' => $e['product_id'],
                    'variant_id' => $e['variant_id'],
                    'direction' => $direction,
                    'action' => $e['action'],
                    'role' => $e['role'],
                    'combo_parent_id' => $e['combo_parent_id'],
                    'source' => $source,
                ],
            ];

            if ($e['delta'] > 0) {
                $inventory->increase($locationId, $e['product_id'], $qty, $e['variant_id'], $context);
            } else {
                $inventory->decrease($locationId, $e['product_id'], $qty, $e['variant_id'], $context);
            }
        }
    }

    /**
     * Reconstruye las líneas (con product_type y detail_id) a partir de filas de
     * detalle persistidas — para revertir un documento location-aware existente.
     *
     * @param  iterable  $details  filas AdjustmentDetail / DamageDetail
     * @return array<int,array{product_id:int,product_variant_id:?int,quantity:float,type:?string,product_type:string,detail_id:int}>
     */
    public function hydrateLines(iterable $details): array
    {
        $out = [];
        foreach ($details as $d) {
            $pid = (int) $d->product_id;
            // Sin filtro de soft-delete: un producto borrado tras crear el
            // documento debe poder revertirse igual.
            $type = DB::table('products')->where('id', $pid)->value('type') ?? 'is_single';
            $out[] = [
                'product_id' => $pid,
                'product_variant_id' => $d->product_variant_id ? (int) $d->product_variant_id : null,
                'quantity' => round((float) $d->quantity, 3),
                'type' => isset($d->type) ? ($d->type ?: null) : null,
                'product_type' => (string) $type,
                'detail_id' => (int) $d->id,
            ];
        }

        return $out;
    }

    // ---- helpers ------------------------------------------------------------

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw ValidationException::withMessages([
                'inventory' => 'LocationAwareStockDocumentService debe ejecutarse dentro de la transacción del documento.',
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,object{combined_product_id:int,quantity:float}> */
    private function comboComponents(int $comboProductId)
    {
        return CombinedProduct::where('product_id', $comboProductId)->get(['combined_product_id', 'quantity']);
    }

    /** IDs (de $productIds) batch-tracked o IMEI. Schema-guarded. */
    private function trackedProductIds(array $productIds): array
    {
        if (! $productIds || ! Schema::hasTable('products')) return [];

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatch && ! $hasImei) return [];

        return DB::table('products')
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($hasBatch, $hasImei) {
                if ($hasBatch) $q->orWhere('is_batch_tracked', 1);
                if ($hasImei) $q->orWhere('is_imei', 1);
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
