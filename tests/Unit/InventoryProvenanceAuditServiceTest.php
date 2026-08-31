<?php

namespace Tests\Unit;

use App\Services\InventoryProvenanceAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Auditor basado en eventos: la diferencia legacy - location POSTERIOR a un
 * baseline puede ser 100% operaciones location-native legítimas y NO stock
 * legacy pendiente. Casos reales de prueba02 (Iphone 15/16 vs Iphone X).
 */
class InventoryProvenanceAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inventory_transition_states', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->unique();
            $t->timestamp('last_reconciled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 14, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 14, 3);
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->text('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('product_warehouse', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('warehouse_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qte', 14, 3)->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private int $wh = 1;
    private int $main;

    private function baseline(string $at): void
    {
        DB::table('inventory_transition_states')->insert([
            'warehouse_id' => $this->wh, 'last_reconciled_at' => $at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function locations(): void
    {
        $this->main = (int) DB::table('inventory_locations')->insertGetId([
            'warehouse_id' => $this->wh, 'code' => 'MAIN', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legacy(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->wh, 'product_variant_id' => $variantId,
            'qte' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stock(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->main, 'product_id' => $productId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function backfillMovement(int $productId, float $qty, string $at, ?int $variantId = null): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => $productId, 'product_variant_id' => $variantId,
            'from_inventory_location_id' => null, 'to_inventory_location_id' => $this->main,
            'quantity' => $qty, 'reference_type' => 'legacy_product_warehouse_backfill',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function movement(string $type, int $productId, float $qty, string $ref, string $at, bool $out = true, ?int $variantId = null, ?array $metadata = null): void
    {
        DB::table('inventory_location_movements')->insert([
            'movement_type' => $type, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'from_inventory_location_id' => $out ? $this->main : null,
            'to_inventory_location_id' => $out ? null : $this->main,
            'quantity' => $qty, 'reference_type' => $ref,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function keyFor(array $audit, int $productId): array
    {
        return collect($audit['keys'])->firstWhere('product_id', $productId);
    }

    /** Caso 1 (Iphone 15): baseline 88=88, luego TransferDispatch -28 => RECONCILED, sin pending. */
    public function test_case1_dispatches_after_baseline_are_not_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(6, 88);
        $this->stock(6, 60);
        $this->backfillMovement(6, 88, '2026-08-22 18:01:44');
        $this->movement('decrease', 6, 10, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->movement('decrease', 6, 10, 'TransferDispatch', '2026-08-24 09:00:00');
        $this->movement('decrease', 6, 8, 'TransferDispatch', '2026-08-25 09:00:00');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 6);

        $this->assertSame('RECONCILED', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
        $this->assertSame(0.0, $row['snapshot_drift']);
        $this->assertSame(-28.0, $row['post_baseline_location_net']);
        $this->assertSame(60.0, $row['expected_location']);
    }

    /** Caso 2 (Iphone 16): baseline 90=90, luego -12 => sin pending. */
    public function test_case2_partial_dispatch_after_baseline_not_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(7, 90);
        $this->stock(7, 78);
        $this->backfillMovement(7, 90, '2026-08-22 18:01:44');
        $this->movement('decrease', 7, 7, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->movement('decrease', 7, 5, 'TransferDispatch', '2026-08-24 09:00:00');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 7);
        $this->assertSame('RECONCILED', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
    }

    /** Caso 3 (Iphone X): opening stock legacy +100 tras baseline, sin movimiento => LEGACY_ONLY_PENDING +100. */
    public function test_case3_legacy_opening_stock_without_location_movement_is_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(8, 100); // creado el 30-ago, sin backfill, sin stock ni movimiento location

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 8);
        $this->assertSame('LEGACY_ONLY_PENDING', $row['classification']);
        $this->assertSame(100.0, $row['legacy_only_pending_quantity']);
        $this->assertFalse($row['baselined']);
    }

    private function auditRow(int $productId): array
    {
        return $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), $productId);
    }

    /**
     * G1 — falsa coincidencia de cantidad: opening stock legacy-only +10 y
     * Purchase location-native independiente +10. NUNCA MIRRORED por coincidencia
     * agregada; la alerta NO desaparece.
     */
    public function test_G1_quantity_coincidence_is_not_mirrored(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 110); // baseline 100 + opening stock legacy +10
        $this->stock(9, 110);  // 100 baseline + Purchase location +10
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 10, 'Purchase', '2026-08-25 09:00:00', out: false);

        $row = $this->auditRow(9);
        $this->assertNotSame('MIRRORED', $row['classification']);
        $this->assertSame('UNKNOWN_REVIEW', $row['classification']); // conservador
        $this->assertSame(0.0, $row['post_baseline_mirror_net']);
    }

    /** G2 — mirror real dual_write: legacy +10 con legacy_shadow_sync +10 asociado => MIRRORED. */
    public function test_G2_real_dual_write_mirror(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 110);
        $this->stock(9, 110);
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 10, 'legacy_shadow_sync', '2026-08-25 09:00:00', out: false);

        $row = $this->auditRow(9);
        $this->assertSame('MIRRORED', $row['classification']);
        $this->assertSame(10.0, $row['post_baseline_mirror_net']);
        $this->assertSame(0.0, $row['post_baseline_native_net']);
    }

    /** G3 — dos eventos distintos con mismo reference_type/cantidad => NO MIRRORED. */
    public function test_G3_same_ref_type_different_events_is_not_mirrored(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 105); // legacy Adjustment +5 (id=10) sobre baseline 100
        $this->stock(9, 105);  // location Adjustment +5 (id=11) — evento distinto
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 5, 'Adjustment', '2026-08-25 09:00:00', out: false, metadata: ['adjustment_id' => 11]);

        $this->assertSame('UNKNOWN_REVIEW', $this->auditRow(9)['classification']);
    }

    /** G4 — mismo evento: enlace explícito por metadata => MIRRORED. */
    public function test_G4_explicit_event_link_is_mirrored(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 105); // legacy Adjustment +5 id=10
        $this->stock(9, 105);
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 5, 'Adjustment', '2026-08-25 09:00:00', out: false, metadata: [
            'mirrors_legacy' => true, 'legacy_reference_type' => 'Adjustment', 'legacy_reference_id' => '10',
        ]);

        $row = $this->auditRow(9);
        $this->assertSame('MIRRORED', $row['classification']);
        $this->assertSame(5.0, $row['post_baseline_mirror_net']);
    }

    /** G5 — cantidad parcial: legacy Purchase +10, mirror probado +6 => resto +4 pendiente. */
    public function test_G5_partial_mirror_leaves_remainder_pending(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 110); // +10 legacy
        $this->stock(9, 106);  // sólo +6 llegó a la ubicación (mirror probado)
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 6, 'legacy_shadow_sync', '2026-08-25 09:00:00', out: false);

        $row = $this->auditRow(9);
        $this->assertSame('LEGACY_ONLY_PENDING', $row['classification']); // NUNCA MIRRORED completo
        $this->assertSame(4.0, $row['legacy_only_pending_quantity']);     // el resto
    }

    /** G6 — múltiples eventos: legacy A+5 y B+5, sólo mirror A +5 => resto +5 pendiente, no todo MIRRORED. */
    public function test_G6_multiple_events_only_proven_part_is_mirrored(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(9, 110); // A +5 + B +5
        $this->stock(9, 105);  // sólo mirror A +5
        $this->backfillMovement(9, 100, '2026-08-22 18:01:44');
        $this->movement('increase', 9, 5, 'legacy_shadow_sync', '2026-08-25 09:00:00', out: false);

        $row = $this->auditRow(9);
        $this->assertSame('LEGACY_ONLY_PENDING', $row['classification']);
        $this->assertSame(5.0, $row['legacy_only_pending_quantity']); // B, no repartido arbitrariamente
    }

    /** Caso 5: drift inexplicado (stock cambió sin movimiento) => UNKNOWN_REVIEW, nunca ADD. */
    public function test_case5_unexplained_drift_is_unknown_review_never_add(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        $this->legacy(10, 40);
        $this->stock(10, 33); // 7 menos que baseline sin ningún movimiento que lo explique
        $this->backfillMovement(10, 40, '2026-08-22 18:01:44');

        $row = $this->keyFor(app(InventoryProvenanceAuditService::class)->auditWarehouse($this->wh), 10);
        $this->assertSame('UNKNOWN_REVIEW', $row['classification']);
        $this->assertSame(0.0, $row['legacy_only_pending_quantity']);
        $this->assertSame(-7.0, $row['snapshot_drift']);
    }

    public function test_audit_aggregates_and_legacy_only_pending_helper(): void
    {
        $this->baseline('2026-08-22 18:11:46');
        $this->locations();
        // 6: reconciled ; 8: pending +100
        $this->legacy(6, 88); $this->stock(6, 60); $this->backfillMovement(6, 88, '2026-08-22 18:01:44');
        $this->movement('decrease', 6, 28, 'TransferDispatch', '2026-08-23 09:00:00');
        $this->legacy(8, 100);

        $svc = app(InventoryProvenanceAuditService::class);
        $audit = $svc->auditWarehouse($this->wh);

        $this->assertSame(100.0, $audit['legacy_only_pending_total']);
        $this->assertSame(1, $audit['counts']['RECONCILED']);
        $this->assertSame(1, $audit['counts']['LEGACY_ONLY_PENDING']);
        $this->assertFalse($audit['has_unknown_review']);

        $pending = $svc->legacyOnlyPending($this->wh);
        $this->assertCount(1, $pending);
        $this->assertSame(8, $pending[0]['product_id']);
    }
}
