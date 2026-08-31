<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditor de divergencia BASADO EN EVENTOS / PROVENANCE, no en igualdad de
 * snapshots.
 *
 * Motivo: tras el backfill (baseline) `product_warehouse` queda congelado como
 * snapshot para los productos ya migrados, mientras `inventory_location_stocks`
 * sigue cambiando por operaciones location-native (traslados, POS, cuarentena…).
 * Por eso `legacy_now - location_now` NO es "stock legacy pendiente": puede ser
 * el registro legítimo de esas operaciones.
 *
 * Baseline = inventory_transition_states.last_reconciled_at, o si no, el
 * created_at más reciente de los movimientos `legacy_product_warehouse_backfill`.
 *
 * Por cada (product_id, variant_key) del almacén:
 *   baseline_quantity        = Σ movimientos legacy_product_warehouse_backfill
 *   post_baseline_net        = Σ (± quantity) de movimientos posteriores al baseline
 *   expected_location        = baseline_quantity + post_baseline_net
 *   snapshot_drift           = location_now - expected_location   (métrica DIAGNÓSTICA)
 *   legacy_now               = Σ product_warehouse.qte
 *
 * Clasificación:
 *   RECONCILED            baselined y legacy_now == baseline_quantity y drift≈0.
 *   MIRRORED              baselined, legacy subió/bajó tras el baseline y ese
 *                         cambio tiene su equivalente location-native (drift≈0).
 *   LEGACY_ONLY_PENDING   existe cantidad legacy SIN equivalente location-native
 *                         (opening stock / compra legacy posterior al baseline,
 *                         o producto nunca migrado). ESTE es el único candidato
 *                         a ADD.
 *   LOCATION_NATIVE_ONLY  hay stock/movimientos por ubicación sin legacy: no es
 *                         divergencia legacy.
 *   UNKNOWN_REVIEW        drift inexplicado, o cambio legacy sin equivalente
 *                         claro. NUNCA ADD automático.
 */
class InventoryProvenanceAuditService
{
    private const EPS = 0.0005;

    /**
     * reference_type generados EXCLUSIVAMENTE por el mirror dual_write
     * (InventoryCompatibilityService::mirrorLegacySnapshot). Enlace inequívoco
     * legacy→location POR DISEÑO: un movimiento con este reference_type SIEMPRE
     * es el espejo de una escritura legacy. NO existe LEGACY_MIRROR_REFS
     * ambiguo — Purchase/Sale/Adjustment/pos_sale son operaciones de negocio y
     * NO prueban provenance por coincidencia de cantidad.
     */
    public const DUAL_WRITE_MIRROR_REFS = [
        'legacy_shadow_sync',
        'legacy_product_warehouse_model_write',
    ];

    /**
     * ¿El movimiento location es un espejo PROBADO de una escritura legacy?
     *
     *  1. reference_type ∈ DUAL_WRITE_MIRROR_REFS  → mirror generado por nosotros.
     *  2. metadata.transition_mode === 'dual_write' → sello del propio mirror.
     *  3. Enlace explícito a un evento legacy concreto: metadata.mirrors_legacy
     *     con legacy_reference_type + legacy_reference_id que igualan al evento
     *     legacy (para un write path que lo garantice; hoy ninguno de negocio lo
     *     hace, así que en la práctica sólo cuentan 1 y 2).
     *
     * NUNCA por reference_type genérico + signo + cantidad agregada.
     *
     * @param  object      $movement  fila de inventory_location_movements (metadata ya decodificada)
     * @param  array|null  $legacyRef ['type'=>?, 'id'=>?] del evento legacy a correlacionar
     */
    public function matchLegacyEventToLocationMovement(object $movement, ?array $legacyRef = null): bool
    {
        if (in_array($movement->reference_type ?? null, self::DUAL_WRITE_MIRROR_REFS, true)) {
            return true;
        }

        $meta = $this->decodeMeta($movement->metadata ?? null);
        if (($meta['transition_mode'] ?? null) === 'dual_write') {
            return true;
        }

        if (($meta['mirrors_legacy'] ?? null) === true
            && isset($meta['legacy_reference_type'], $meta['legacy_reference_id'])) {
            if ($legacyRef === null) return true;
            return (string) $meta['legacy_reference_type'] === (string) ($legacyRef['type'] ?? '')
                && (string) $meta['legacy_reference_id'] === (string) ($legacyRef['id'] ?? '');
        }

        return false;
    }

    private function decodeMeta($raw): array
    {
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    public function auditWarehouse(int $warehouseId): array
    {
        $locationIds = $this->warehouseLocationIds($warehouseId);
        $baselineAt = $this->baselineAt($warehouseId, $locationIds);

        $baseline = $this->backfillBaselineMap($locationIds);
        // $netMirror = net de movimientos que son mirror PROBADO (no por nombre).
        [$net, $netMirror] = $this->postBaselineMovementMaps($locationIds, $baselineAt);
        $legacyNow = $this->legacyMap($warehouseId);
        $locationNow = $this->locationMap($locationIds);

        $keys = array_values(array_unique(array_merge(
            array_keys($baseline), array_keys($net), array_keys($legacyNow), array_keys($locationNow)
        )));
        sort($keys);

        $rows = [];
        $counts = [
            'RECONCILED' => 0, 'MIRRORED' => 0, 'LEGACY_ONLY_PENDING' => 0,
            'LOCATION_NATIVE_ONLY' => 0, 'UNKNOWN_REVIEW' => 0,
        ];
        $legacyOnlyPendingTotal = 0.0;
        $snapshotDriftTotal = 0.0;

        foreach ($keys as $k) {
            [$productId, $variantKey] = array_map('intval', explode(':', $k));
            $b = round((float) ($baseline[$k] ?? 0.0), 3);
            $baselined = array_key_exists($k, $baseline);
            $leg = round((float) ($legacyNow[$k] ?? 0.0), 3);
            $loc = round((float) ($locationNow[$k] ?? 0.0), 3);
            $n = round((float) ($net[$k] ?? 0.0), 3);
            // net del mirror PROBADO y net "nativo" (todo lo demás: dispatch,
            // receipt, POS bridge, ajuste location-aware, Purchase de negocio…).
            $nMirror = round((float) ($netMirror[$k] ?? 0.0), 3);
            $nNative = round($n - $nMirror, 3);
            $expected = round($b + $n, 3);
            $drift = round($loc - $expected, 3);

            $classification = 'UNKNOWN_REVIEW';
            $pending = 0.0;

            if ($baselined) {
                if (abs($drift) > self::EPS) {
                    // La ubicación no cuadra con baseline + movimientos: algo
                    // cambió el stock por ubicación sin dejar movimiento.
                    $classification = 'UNKNOWN_REVIEW';
                } elseif (abs($leg - $b) <= self::EPS) {
                    // legacy NO cambió desde el baseline: el gap con la ubicación
                    // es 100% operaciones location-native legítimas (dispatch…).
                    $classification = 'RECONCILED';
                } elseif ($leg > $b + self::EPS) {
                    // legacy AUMENTÓ desde el baseline.
                    $increase = round($leg - $b, 3);
                    $mirroredIn = max(0.0, $nMirror); // sólo mirror PROBADO
                    $remaining = round($increase - $mirroredIn, 3);
                    if (abs($remaining) <= self::EPS) {
                        $classification = 'MIRRORED';
                    } elseif (abs($nNative) > self::EPS) {
                        // hay actividad location-native NO-mirror en la ventana:
                        // no se puede separar el aumento legacy de esa actividad
                        // con certeza. Coincidencia de cantidad != provenance.
                        $classification = 'UNKNOWN_REVIEW';
                    } else {
                        // aumento legacy sin NINGÚN movimiento location que lo
                        // explique (Iphone X): cantidad legacy sin migrar.
                        $classification = 'LEGACY_ONLY_PENDING';
                        $pending = round(max(0.0, $remaining), 3);
                    }
                } else { // $leg < $b - eps  — legacy DISMINUYÓ desde el baseline.
                    $decrease = round($b - $leg, 3);
                    $mirroredOut = abs(min(0.0, $nMirror)); // sólo mirror PROBADO
                    $classification = (abs($mirroredOut - $decrease) <= self::EPS) ? 'MIRRORED' : 'UNKNOWN_REVIEW';
                }
            } else {
                // Sin baseline: no hay punto de partida verificable.
                if ($leg <= self::EPS && $loc <= self::EPS && abs($n) <= self::EPS) {
                    $classification = 'RECONCILED';
                } elseif ($leg <= self::EPS && ($loc > self::EPS || abs($n) > self::EPS)) {
                    $classification = 'LOCATION_NATIVE_ONLY';
                } elseif (abs($n) <= self::EPS && abs($leg - $loc) <= self::EPS) {
                    // legacy y ubicación coinciden y no hubo movimientos.
                    $classification = 'RECONCILED';
                } elseif (abs($n) <= self::EPS && $leg > $loc + self::EPS) {
                    // legacy excede la ubicación y NO hay movimiento que lo
                    // explique => cantidad legacy sin migrar.
                    $classification = 'LEGACY_ONLY_PENDING';
                    $pending = round($leg - $loc, 3);
                } else {
                    // Hay movimientos que no podemos anclar a un baseline, o la
                    // ubicación excede a legacy: no es concluyente.
                    $classification = 'UNKNOWN_REVIEW';
                }
            }

            $counts[$classification]++;
            if ($classification === 'LEGACY_ONLY_PENDING') $legacyOnlyPendingTotal += $pending;
            $snapshotDriftTotal += $drift;

            $rows[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantKey > 0 ? $variantKey : null,
                'baselined' => $baselined,
                'baseline_quantity' => $b,
                'legacy_now' => $leg,
                'current_location' => $loc,
                // TODOS los movimientos posteriores al baseline (para reconstruir
                // expected_location).
                'post_baseline_location_net' => $n,
                // Sólo movimientos generados por el mirror dual_write.
                'post_baseline_mirror_net' => $nMirror,
                // location_net − mirror_net = movimientos independientes del
                // mirror (dispatch, receipt, ajuste location-aware…). ESTA es la
                // señal del safety guard de runtime.
                'post_baseline_native_net' => $nNative,
                'expected_location' => $expected,
                'snapshot_drift' => $drift,
                'classification' => $classification,
                'legacy_only_pending_quantity' => round($pending, 3),
            ];
        }

        return [
            'warehouse_id' => $warehouseId,
            'baseline_at' => $baselineAt,
            'keys' => $rows,
            'counts' => $counts,
            'legacy_only_pending_total' => round($legacyOnlyPendingTotal, 3),
            'snapshot_drift_total' => round($snapshotDriftTotal, 3),
            'has_unknown_review' => $counts['UNKNOWN_REVIEW'] > 0,
        ];
    }

    /** Sólo las claves LEGACY_ONLY_PENDING (candidatas a reconciliación). */
    public function legacyOnlyPending(int $warehouseId): array
    {
        return array_values(array_filter(
            $this->auditWarehouse($warehouseId)['keys'],
            fn ($row) => $row['classification'] === 'LEGACY_ONLY_PENDING'
        ));
    }

    /**
     * Resumen por producto (agregado sobre todos los almacenes relevantes) para
     * superficies de lectura como InventoryVisibilityController. NO devuelve
     * snapshot_drift como "pendiente": es sólo diagnóstico.
     *
     * @param  int[]  $productIds
     * @return array<int, array{legacy_only_pending_quantity: float, snapshot_drift: float, has_unknown_review: bool}>
     */
    public function summaryByProduct(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (! $productIds) return [];

        $warehouseIds = $this->warehousesTouchingProducts($productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $out[$pid] = ['legacy_only_pending_quantity' => 0.0, 'snapshot_drift' => 0.0, 'has_unknown_review' => false];
        }

        foreach ($warehouseIds as $whId) {
            foreach ($this->auditWarehouse($whId)['keys'] as $row) {
                $pid = (int) $row['product_id'];
                if (! isset($out[$pid])) continue;
                $out[$pid]['snapshot_drift'] = round($out[$pid]['snapshot_drift'] + (float) $row['snapshot_drift'], 3);
                if ($row['classification'] === 'LEGACY_ONLY_PENDING') {
                    $out[$pid]['legacy_only_pending_quantity'] = round(
                        $out[$pid]['legacy_only_pending_quantity'] + (float) $row['legacy_only_pending_quantity'], 3
                    );
                }
                if ($row['classification'] === 'UNKNOWN_REVIEW') {
                    $out[$pid]['has_unknown_review'] = true;
                }
            }
        }

        return $out;
    }

    private function warehousesTouchingProducts(array $productIds): array
    {
        $ids = [];
        if (Schema::hasTable('product_warehouse')) {
            $ids = DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereNull('deleted_at')
                ->distinct()->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
        }
        if (Schema::hasTable('inventory_location_stocks') && Schema::hasTable('inventory_locations')) {
            $more = DB::table('inventory_location_stocks as s')
                ->join('inventory_locations as il', 'il.id', '=', 's.inventory_location_id')
                ->whereIn('s.product_id', $productIds)
                ->whereNotNull('il.warehouse_id')
                ->whereNull('il.deleted_at')
                ->distinct()->pluck('il.warehouse_id')->map(fn ($i) => (int) $i)->all();
            $ids = array_merge($ids, $more);
        }
        return array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
    }

    private function baselineAt(int $warehouseId, array $locationIds): ?string
    {
        if (Schema::hasTable('inventory_transition_states')) {
            $ts = DB::table('inventory_transition_states')
                ->where('warehouse_id', $warehouseId)
                ->value('last_reconciled_at');
            if ($ts) return (string) $ts;
        }

        if ($locationIds && Schema::hasTable('inventory_location_movements')) {
            $ts = DB::table('inventory_location_movements')
                ->where('reference_type', 'legacy_product_warehouse_backfill')
                ->whereIn('to_inventory_location_id', $locationIds)
                ->max('created_at');
            if ($ts) return (string) $ts;
        }

        return null;
    }

    private function backfillBaselineMap(array $locationIds): array
    {
        if (! $locationIds || ! Schema::hasTable('inventory_location_movements')) return [];

        $map = [];
        foreach (DB::table('inventory_location_movements')
            ->where('reference_type', 'legacy_product_warehouse_backfill')
            ->whereIn('to_inventory_location_id', $locationIds)
            ->groupBy('product_id', DB::raw('COALESCE(product_variant_id, 0)'))
            ->selectRaw('product_id, COALESCE(product_variant_id, 0) as vk, SUM(quantity) as q')
            ->get() as $r) {
            $map[$r->product_id.':'.((int) $r->vk)] = round((float) $r->q, 3);
        }
        return $map;
    }

    /**
     * @return array{0: array<string,float>, 1: array<string,float>}
     *         [net (todos los movimientos posteriores al baseline),
     *          netMirror (sólo movimientos que son mirror PROBADO — ver
     *          matchLegacyEventToLocationMovement)]
     */
    private function postBaselineMovementMaps(array $locationIds, ?string $baselineAt): array
    {
        if (! $locationIds || ! Schema::hasTable('inventory_location_movements')) return [[], []];

        $query = DB::table('inventory_location_movements')
            ->where('reference_type', '!=', 'legacy_product_warehouse_backfill')
            ->whereNotIn('movement_type', ['reserve', 'release'])
            ->where(function ($w) use ($locationIds) {
                $w->whereIn('from_inventory_location_id', $locationIds)
                  ->orWhereIn('to_inventory_location_id', $locationIds);
            });
        // Los movimientos del mirror dual_write SIEMPRE son posteriores al
        // baseline por definición (sólo existen tras activar dual_write) — se
        // incluyen aunque su timestamp empate con el del backfill.
        if ($baselineAt) {
            $query->where(function ($w) use ($baselineAt) {
                $w->where('created_at', '>', $baselineAt)
                  ->orWhereIn('reference_type', self::DUAL_WRITE_MIRROR_REFS);
            });
        }

        $net = [];
        $netMirror = [];
        foreach ($query->get(['product_id', 'product_variant_id', 'from_inventory_location_id', 'to_inventory_location_id', 'quantity', 'reference_type', 'metadata']) as $m) {
            $k = ((int) $m->product_id).':'.((int) ($m->product_variant_id ?: 0));
            $qty = round((float) $m->quantity, 3);
            $delta = 0.0;
            if (in_array((int) $m->to_inventory_location_id, $locationIds, true)) $delta += $qty;
            if (in_array((int) $m->from_inventory_location_id, $locationIds, true)) $delta -= $qty;

            $net[$k] = round(($net[$k] ?? 0.0) + $delta, 3);
            // MIRROR PROBADO por identidad de evento — nunca por coincidencia de
            // cantidad ni reference_type de negocio.
            if ($this->matchLegacyEventToLocationMovement($m)) {
                $netMirror[$k] = round(($netMirror[$k] ?? 0.0) + $delta, 3);
            }
        }
        return [$net, $netMirror];
    }

    private function warehouseLocationIds(int $warehouseId): array
    {
        if (! Schema::hasTable('inventory_locations')) return [];

        return DB::table('inventory_locations')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('warehouse_id', $warehouseId)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function legacyMap(int $warehouseId): array
    {
        if (! Schema::hasTable('product_warehouse')) return [];

        $map = [];
        foreach (DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->groupBy('product_id', DB::raw('COALESCE(product_variant_id, 0)'))
            ->selectRaw('product_id, COALESCE(product_variant_id, 0) as vk, SUM(qte) as q')
            ->get() as $r) {
            $map[$r->product_id.':'.((int) $r->vk)] = round((float) $r->q, 3);
        }
        return $map;
    }

    private function locationMap(array $locationIds): array
    {
        if (! $locationIds || ! Schema::hasTable('inventory_location_stocks')) return [];

        $map = [];
        foreach (DB::table('inventory_location_stocks')
            ->whereIn('inventory_location_id', $locationIds)
            ->groupBy('product_id', 'variant_key')
            ->selectRaw('product_id, variant_key, SUM(quantity) as q')
            ->get() as $r) {
            $map[$r->product_id.':'.((int) $r->variant_key)] = round((float) $r->q, 3);
        }
        return $map;
    }
}
